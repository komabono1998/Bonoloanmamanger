<?php
/**
 * Harmonised loan application workflow.
 *
 * The application (loan) is the central record. Eleven stages are worked out from the records themselves - never from a "Next"
 * button - so a stage can only count as complete when its requirements are really met:
 *   1 Client registration -> 2 KYC & documents -> 3 Guarantors -> 4 Collateral -> 5 Application -> 6 Credit assessment ->
 *   7 Review & approval -> 8 Offer & acceptance -> 9 Pre-disbursement -> 10 Disbursement -> 11 Account activation.
 * The services call gate()/preDisbursement() on the server, so the rules hold whatever screen or request is used.
 */
class Workflow
{
    public const STAGES = [1 => 'Client registration', 2 => 'KYC & documents', 3 => 'Guarantors', 4 => 'Collateral / security', 5 => 'Loan application', 6 => 'Credit assessment',
        7 => 'Review & approval', 8 => 'Offer & acceptance', 9 => 'Pre-disbursement checks', 10 => 'Disbursement', 11 => 'Account activation'];
    public const KYC_KINDS = ['national_id' => 'National ID / passport', 'proof_of_residence' => 'Proof of residence', 'proof_of_income' => 'Proof of income / business', 'photo' => 'Photograph', 'other_kyc' => 'Other KYC document'];
    public const SECURED = ['Accepted', 'Pledged', 'Held'];

    // ------------------------------------------------------------------ product configuration
    public static function requiredDocs(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', setting('wf_required_docs'))), fn($k) => isset(self::KYC_KINDS[$k])));
    }
    public static function minGuarantors(): int { return max(0, (int)setting('wf_min_guarantors')); }
    public static function collateralPolicy(): string { return in_array(setting('wf_collateral'), ['none', 'optional', 'required'], true) ? setting('wf_collateral') : 'optional'; }
    public static function exceptionsAllowed(): bool { return setting('wf_allow_exceptions') !== '0'; }

    public static function log(int $loanId, string $stage, string $event, string $reason = ''): void
    {
        insert('loan_workflow_log', ['loan_id' => $loanId, 'stage' => $stage, 'event' => $event, 'reason' => $reason !== '' ? $reason : null, 'user_id' => uid(), 'at' => now()]);
    }

    /** Client-level events (KYC) are written to the history of every application of that client that is still open. */
    public static function logForClient(int $clientId, string $stage, string $event, string $reason = ''): void
    {
        foreach (rows("SELECT id FROM loans WHERE client_id = ? AND status IN ('Draft','Submitted','Under Review','Returned for Correction','Approved')", [$clientId]) as $l) self::log((int)$l['id'], $stage, $event, $reason);
    }

    // ------------------------------------------------------------------ stage evaluation
    /** Stage 1-4 checks for one loan. Returns [stage => ['state' => done|na|blocked, 'issues' => [], 'note' => '']]. */
    public static function prerequisites(array $loan): array
    {
        $client = row('SELECT * FROM clients WHERE id = ?', [$loan['client_id']]);
        $out = [];

        // 1 client registration
        $i = [];
        if (!$client) $i[] = 'The client record is missing.';
        else {
            foreach (['full_name' => 'name', 'phone' => 'phone number', 'nin' => 'national ID number', 'branch_id' => 'branch'] as $k => $l) if (trim((string)($client[$k] ?? '')) === '') $i[] = 'The client has no ' . $l . '.';
            if ($client['status'] !== 'Active') $i[] = 'The client is ' . strtolower($client['status']) . '.';
        }
        if (empty($loan['officer_id'])) $i[] = 'No loan officer is assigned.';
        $out[1] = ['state' => $i ? 'blocked' : 'done', 'issues' => $i, 'note' => $client ? $client['client_no'] : ''];

        // 2 KYC and documents
        $i = [];
        foreach (self::requiredDocs() as $kind) {
            $docs = rows("SELECT verify_status FROM attachments WHERE kind = ? AND ((entity_type = 'client' AND entity_id = ?) OR (entity_type = 'loan' AND entity_id = ?)) ORDER BY id DESC", [$kind, $loan['client_id'], $loan['id']]);
            $st = array_column($docs, 'verify_status');
            if (in_array('Verified', $st, true)) continue;
            $name = self::KYC_KINDS[$kind];
            $i[] = !$docs ? $name . ' has not been uploaded.' : (in_array('Pending', $st, true) ? $name . ' is awaiting verification.' : $name . ' was rejected; upload a corrected copy.');
        }
        if ($client && $client['kyc_status'] !== 'Verified') $i[] = $client['kyc_status'] === 'Rejected' ? 'The client KYC was rejected and must be corrected and verified again.' : 'The client KYC profile has not been verified yet.';
        $out[2] = ['state' => $i ? 'blocked' : 'done', 'issues' => $i, 'note' => ''];

        // 3 guarantors
        $min = self::minGuarantors();
        $gs = rows("SELECT lg.*, g.surname, g.first_name FROM loan_guarantors lg JOIN guarantors g ON g.id = lg.guarantor_id WHERE lg.loan_id = ? AND lg.status = 'Active'", [$loan['id']]);
        $ok = 0; $i = [];
        foreach ($gs as $g) {
            $n = $g['first_name'] . ' ' . $g['surname'];
            if ($g['verify_status'] === 'Verified' && (int)$g['declaration_accepted'] === 1) $ok++;
            elseif ($g['verify_status'] === 'Rejected') $i[] = 'Guarantor ' . $n . ' was rejected.';
            elseif ($g['verify_status'] !== 'Verified') $i[] = 'Guarantor ' . $n . ' is awaiting verification.';
            else $i[] = 'Guarantor ' . $n . ' has not recorded consent to the guarantee.';
        }
        if ($ok >= $min) { $state = ($min === 0 && !$gs) ? 'na' : 'done'; $i = $ok >= $min ? [] : $i; $note = $state === 'na' ? 'Not required by the loan product' : ''; }
        elseif (!empty($loan['guarantor_na_reason']) && self::exceptionsAllowed()) { $state = 'na'; $i = []; $note = 'Authorised exception: ' . $loan['guarantor_na_reason']; }
        else { $state = 'blocked'; array_unshift($i, 'The loan product requires ' . $min . ' verified guarantor' . ($min === 1 ? '' : 's') . ' (' . $ok . ' verified so far).'); $note = ''; }
        $out[3] = ['state' => $state, 'issues' => $i, 'note' => $note];

        // 4 collateral
        $pol = self::collateralPolicy();
        $sec = (int)val("SELECT COUNT(*) FROM securities WHERE loan_id = ? AND status IN ('Accepted','Pledged','Held')", [$loan['id']]);
        $pend = (int)val("SELECT COUNT(*) FROM securities WHERE loan_id = ? AND status IN ('Proposed','Under Verification')", [$loan['id']]);
        if ($sec > 0) $out[4] = ['state' => 'done', 'issues' => [], 'note' => ''];
        elseif ($pol === 'required') {
            if (!empty($loan['collateral_na_reason']) && self::exceptionsAllowed()) $out[4] = ['state' => 'na', 'issues' => [], 'note' => 'Authorised exception: ' . $loan['collateral_na_reason']];
            else $out[4] = ['state' => 'blocked', 'issues' => [$pend ? 'Security has been proposed but not yet accepted.' : 'The loan product requires accepted security; none has been recorded.'], 'note' => ''];
        } else $out[4] = ['state' => 'na', 'issues' => [], 'note' => $pol === 'none' ? 'The loan product takes no security' : 'Unsecured lending is allowed for this product'];
        return $out;
    }

    /** All 11 stages with state: done | na | current | blocked | pending | stopped | returned. */
    public static function stages(array $loan): array
    {
        $pre = self::prerequisites($loan);
        $status = $loan['status'];
        $assess = Loans::latestAssessment((int)$loan['id']);
        $disbursed = in_array($status, ['Disbursed', 'Active', 'Overdue', 'Closed'], true) || (bool)val('SELECT COUNT(*) FROM disbursements WHERE loan_id = ?', [$loan['id']]);
        $stopped = in_array($status, ['Rejected', 'Cancelled'], true);
        $approved = !empty($loan['approved_by']) && !$stopped;
        $st = [];
        foreach ($pre as $n => $s) $st[$n] = $s + ['detail' => ''];
        // 5 application
        $submitted = !in_array($status, ['Draft', 'Returned for Correction'], true) && !$stopped;
        $prereqOk = !array_filter($pre, fn($s) => $s['state'] === 'blocked');
        $st[5] = ['state' => $submitted || $approved || $disbursed ? 'done' : ($prereqOk ? 'current' : 'blocked'), 'issues' => !$prereqOk && !$submitted ? ['Complete stages 1 to 4 before the application can be submitted.'] : [], 'note' => $loan['loan_no']];
        // 6 assessment
        if ($assess) $st[6] = ['state' => 'done', 'issues' => [], 'note' => $assess['recommendation'] ?? ''];
        elseif (setting('assessment_required') !== '1') $st[6] = ['state' => 'na', 'issues' => [], 'note' => 'Not required by policy'];
        else $st[6] = ['state' => $submitted ? 'current' : 'pending', 'issues' => $submitted ? ['The credit assessment has not been completed.'] : [], 'note' => ''];
        // 7 approval
        if ($status === 'Rejected') $st[7] = ['state' => 'stopped', 'issues' => [], 'note' => 'Rejected: ' . ($loan['rejection_reason'] ?? '')];
        elseif ($status === 'Cancelled') $st[7] = ['state' => 'stopped', 'issues' => [], 'note' => 'Cancelled'];
        elseif ($status === 'Returned for Correction') $st[7] = ['state' => 'returned', 'issues' => ['Returned for correction' . ($loan['return_stage'] ? ' (' . (self::STAGES[(int)$loan['return_stage']] ?? '') . ')' : '') . '.'], 'note' => ''];
        elseif ($approved) $st[7] = ['state' => 'done', 'issues' => [], 'note' => 'Approved ' . fmt_date($loan['approval_date'])];
        else $st[7] = ['state' => $submitted && ($assess || setting('assessment_required') !== '1') ? 'current' : 'pending', 'issues' => [], 'note' => ''];
        // 8 offer
        if ($loan['acceptance_status'] === 'Accepted' && $approved) $st[8] = ['state' => 'done', 'issues' => [], 'note' => 'Accepted ' . fmt_date($loan['acceptance_date'])];
        elseif ($loan['acceptance_status'] === 'Declined' && $approved) $st[8] = ['state' => 'stopped', 'issues' => [], 'note' => 'Declined by the client'];
        else $st[8] = ['state' => $approved ? 'current' : 'pending', 'issues' => $approved && Loans::offerExpired($loan) ? ['The offer has expired.'] : [], 'note' => ''];
        // 9 pre-disbursement
        if ($disbursed || $loan['predisb_at']) $st[9] = ['state' => 'done', 'issues' => [], 'note' => $loan['predisb_at'] ? 'Checked ' . fmt_dt($loan['predisb_at']) : ''];
        elseif ($loan['acceptance_status'] === 'Accepted' && $approved) { $pd = self::preDisbursement($loan); $st[9] = ['state' => $pd ? 'blocked' : 'current', 'issues' => $pd, 'note' => $pd ? '' : 'All checks pass']; }
        else $st[9] = ['state' => 'pending', 'issues' => [], 'note' => ''];
        // 10 disbursement
        $st[10] = ['state' => $disbursed ? 'done' : ($st[9]['state'] === 'current' ? 'current' : 'pending'), 'issues' => [], 'note' => $disbursed && $loan['disbursement_date'] ? fmt_date($loan['disbursement_date']) : ''];
        // 11 activation
        $active = in_array($status, ['Active', 'Overdue', 'Closed'], true) && (bool)val('SELECT COUNT(*) FROM repayment_schedules WHERE loan_id = ?', [$loan['id']]);
        $st[11] = ['state' => $active ? 'done' : 'pending', 'issues' => [], 'note' => $active ? 'Loan account ' . $loan['loan_no'] : ''];
        ksort($st);
        // the first stage that is neither finished nor not-applicable is the current one
        $seen = false;
        foreach ($st as $n => &$s) {
            $s['no'] = $n; $s['name'] = self::STAGES[$n];
            if (!$seen && !in_array($s['state'], ['done', 'na'], true)) { $seen = true; if ($s['state'] === 'pending') $s['state'] = 'current'; }
        }
        unset($s);
        return $st;
    }

    /** Throw with every unmet requirement of stages 1..$upTo. */
    public static function gate(array $loan, int $upTo = 4, string $what = 'continue'): void
    {
        $issues = [];
        foreach (self::prerequisites($loan) as $n => $s) if ($n <= $upTo && $s['state'] === 'blocked') foreach ($s['issues'] as $m) $issues[] = '[' . self::STAGES[$n] . '] ' . $m;
        must(!$issues, 'This application cannot ' . $what . ' yet: ' . implode(' ', $issues));
    }

    /** Conditions that must hold before funds are released. Empty list = all pass. */
    public static function preDisbursement(array $loan): array
    {
        $i = [];
        if ($loan['status'] !== 'Approved') $i[] = 'The application is not in the approved state.';
        if ($loan['acceptance_status'] !== 'Accepted') $i[] = 'The borrower has not accepted the approved offer.';
        if (Loans::offerExpired($loan)) $i[] = 'The approval/offer is no longer valid (expired ' . fmt_date($loan['offer_expiry']) . ').';
        if ((int)($loan['approved_version'] ?? 0) !== (int)$loan['app_version']) $i[] = 'The application was changed after it was approved; it must be re-approved.';
        foreach (self::prerequisites($loan) as $n => $s) if ($s['state'] === 'blocked') foreach ($s['issues'] as $m) $i[] = '[' . self::STAGES[$n] . '] ' . $m;
        $p = (int)$loan['approved_principal']; $fees = (int)$loan['total_fees']; $net = (int)$loan['net_disbursement'];
        if ($p <= 0 || $net !== $p - ((int)$loan['fees_deducted'] ? $fees : 0)) $i[] = 'The amount to release does not reconcile with the approved principal and fees.';
        if (val('SELECT COUNT(*) FROM disbursements WHERE loan_id = ?', [$loan['id']])) $i[] = 'A disbursement has already been recorded for this loan (duplicate payment blocked).';
        return $i;
    }

    // ------------------------------------------------------------------ verification actions
    private static function independent(?int $madeBy): void
    {
        must(!$madeBy || (int)$madeBy !== (int)uid() || can('authority.branch') || can('authority.institution'), 'Separation of duties: you cannot verify a record that you captured yourself. Ask another authorised user.');
    }

    public static function verifyDocument(int $attId, bool $ok, string $note): void
    {
        require_perm('kyc.verify');
        $a = row('SELECT * FROM attachments WHERE id = ?', [$attId]);
        must((bool)$a && in_array($a['entity_type'], ['client', 'loan', 'guarantor', 'security'], true), 'Document not found.');
        if ($a['branch_id']) assert_branch((int)$a['branch_id']);
        must($ok || trim($note) !== '', 'State why the document is rejected.');
        self::independent($a['uploaded_by'] ? (int)$a['uploaded_by'] : null);
        q('UPDATE attachments SET verify_status = ?, verified_by = ?, verified_at = ?, verify_note = ? WHERE id = ?', [$ok ? 'Verified' : 'Rejected', uid(), now(), trim($note) ?: null, $attId]);
        audit($ok ? 'Document verified' : 'Document rejected', 'Workflow', 'attachment', $attId, $a['verify_status'], $ok ? 'Verified' : 'Rejected', $note, $a['original_name']);
        $what = $a['original_name'] . ($note ? ': ' . $note : '');
        if ($a['entity_type'] === 'loan') self::log((int)$a['entity_id'], 'KYC & documents', $ok ? 'document verified' : 'document rejected', $what);
        elseif ($a['entity_type'] === 'client') self::logForClient((int)$a['entity_id'], 'KYC & documents', $ok ? 'document verified' : 'document rejected', $what);
    }

    public static function verifyClient(int $clientId, bool $ok, string $note): void
    {
        require_perm('kyc.verify');
        $c = row('SELECT * FROM clients WHERE id = ?', [$clientId]);
        must((bool)$c, 'Client not found.');
        assert_branch((int)$c['branch_id']);
        must($ok || trim($note) !== '', 'State why the KYC profile is rejected.');
        self::independent($c['registered_by'] ? (int)$c['registered_by'] : null);
        if ($ok) {
            $missing = [];
            foreach (self::requiredDocs() as $k) if (!val("SELECT COUNT(*) FROM attachments WHERE entity_type = 'client' AND entity_id = ? AND kind = ? AND verify_status = 'Verified'", [$clientId, $k])) $missing[] = self::KYC_KINDS[$k];
            must(!$missing, 'Verify these required documents first: ' . implode(', ', $missing) . '.');
        }
        q('UPDATE clients SET kyc_status = ?, kyc_verified_by = ?, kyc_verified_at = ?, kyc_note = ? WHERE id = ?', [$ok ? 'Verified' : 'Rejected', uid(), now(), trim($note) ?: null, $clientId]);
        audit($ok ? 'Client KYC verified' : 'Client KYC rejected', 'Workflow', 'client', $clientId, $c['kyc_status'], $ok ? 'Verified' : 'Rejected', $note, '', null, null, (int)$c['branch_id']);
        self::logForClient($clientId, 'KYC & documents', $ok ? 'client KYC verified' : 'client KYC rejected', $note);
    }

    public static function verifyGuarantor(int $loanGuarantorId, bool $ok, string $note): void
    {
        require_perm('kyc.verify');
        $g = row('SELECT lg.*, l.branch_id FROM loan_guarantors lg JOIN loans l ON l.id = lg.loan_id WHERE lg.id = ?', [$loanGuarantorId]);
        must((bool)$g, 'Guarantor link not found.');
        assert_branch((int)$g['branch_id']);
        must($ok || trim($note) !== '', 'State why the guarantor is rejected.');
        self::independent($g['created_by'] ? (int)$g['created_by'] : null);
        if ($ok) must((int)$g['declaration_accepted'] === 1, 'The guarantor has not recorded consent; capture the signed declaration first.');
        q('UPDATE loan_guarantors SET verify_status = ?, verified_by = ?, verified_at = ?, verify_note = ? WHERE id = ?', [$ok ? 'Verified' : 'Rejected', uid(), now(), trim($note) ?: null, $loanGuarantorId]);
        audit($ok ? 'Guarantor verified' : 'Guarantor rejected', 'Workflow', 'loan', (int)$g['loan_id'], $g['verify_status'], $ok ? 'Verified' : 'Rejected', $note, '', null, null, (int)$g['branch_id']);
        self::log((int)$g['loan_id'], 'Guarantors', $ok ? 'guarantor verified' : 'guarantor rejected', $note);
    }

    /** Authorised exception for stage 3 or 4 (recorded with a reason; allowed only when the product configuration permits exceptions). */
    public static function markNotApplicable(int $loanId, string $stage, string $reason): void
    {
        require_perm('loan.approve');
        must(self::exceptionsAllowed(), 'The loan product configuration does not allow exceptions to mandatory requirements.');
        must(in_array($stage, ['guarantors', 'collateral'], true), 'Only the guarantor and collateral stages can be marked not applicable.');
        must(trim($reason) !== '', 'A documented reason is required.');
        $l = Loans::get($loanId);
        must(in_array($l['status'], ['Draft', 'Submitted', 'Under Review', 'Returned for Correction'], true), 'The application is no longer open for this change.');
        assert_branch((int)$l['branch_id']);
        must((int)$l['officer_id'] !== (int)uid(), 'Separation of duties: you cannot authorise an exception on your own application.');
        $col = $stage === 'guarantors' ? 'guarantor_na' : 'collateral_na';
        q("UPDATE loans SET {$col}_reason = ?, {$col}_by = ?, updated_at = ? WHERE id = ?", [trim($reason), uid(), now(), $loanId]);
        audit('Workflow exception authorised', 'Workflow', 'loan', $loanId, null, $stage . ' not applicable', $reason);
        self::log($loanId, $stage === 'guarantors' ? 'Guarantors' : 'Collateral / security', 'marked not applicable (exception)', $reason);
    }

    public static function clearException(int $loanId, string $stage): void
    {
        require_perm('loan.approve');
        $col = $stage === 'guarantors' ? 'guarantor_na' : 'collateral_na';
        q("UPDATE loans SET {$col}_reason = NULL, {$col}_by = NULL, updated_at = ? WHERE id = ?", [now(), $loanId]);
        audit('Workflow exception withdrawn', 'Workflow', 'loan', $loanId, null, $stage);
        self::log($loanId, $stage, 'exception withdrawn');
    }

    /** Snapshot of the verified records the approval relied on, kept with the loan. */
    public static function reliedOn(array $loan): string
    {
        $docs = array_column(rows("SELECT id FROM attachments WHERE verify_status = 'Verified' AND ((entity_type = 'client' AND entity_id = ?) OR (entity_type = 'loan' AND entity_id = ?)) ORDER BY id", [$loan['client_id'], $loan['id']]), 'id');
        $gs = array_column(rows("SELECT guarantor_id FROM loan_guarantors WHERE loan_id = ? AND status = 'Active' AND verify_status = 'Verified'", [$loan['id']]), 'guarantor_id');
        $sec = array_column(rows("SELECT id FROM securities WHERE loan_id = ? AND status IN ('Accepted','Pledged','Held')", [$loan['id']]), 'id');
        return json_encode(['version' => (int)$loan['app_version'], 'documents' => array_map('intval', $docs), 'guarantors' => array_map('intval', $gs), 'securities' => array_map('intval', $sec)]);
    }

    /** When a correction is requested at stage $stage, the records of that stage must be verified again. */
    public static function reopenStage(int $loanId, string $stage): void
    {
        $l = Loans::get($loanId);
        if ($stage === 'kyc') {
            q("UPDATE clients SET kyc_status = 'Pending' WHERE id = ?", [$l['client_id']]);
        } elseif ($stage === 'guarantors') {
            q("UPDATE loan_guarantors SET verify_status = 'Pending', verified_by = NULL, verified_at = NULL WHERE loan_id = ? AND status = 'Active'", [$loanId]);
        }
    }

    public static function history(int $loanId): array
    {
        return rows('SELECT w.*, u.full_name FROM loan_workflow_log w LEFT JOIN users u ON u.id = w.user_id WHERE w.loan_id = ? ORDER BY w.id DESC', [$loanId]);
    }
}
