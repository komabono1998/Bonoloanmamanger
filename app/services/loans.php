<?php
/** Loan lifecycle: application, assessment, approval, offer acceptance, disbursement, status tracking, closure. */

final class Loans
{
    public const OPEN_STATUSES = ['Active', 'Overdue'];

    public static function get(int $id): array
    {
        $l = row('SELECT l.*, c.full_name AS client_name, c.client_no, c.phone AS client_phone, u.full_name AS officer_name
                  FROM loans l JOIN clients c ON c.id = l.client_id JOIN users u ON u.id = l.officer_id WHERE l.id = ?', [$id]);
        must((bool)$l, 'Loan does not exist.');
        assert_branch((int)$l['branch_id']);
        return $l;
    }

    /** Outstanding balances derived from the ledger (never from an editable balance field). */
    public static function balances(int $loanId, ?string $asOf = null): array
    {
        $sql = 'SELECT component, COALESCE(SUM(amount),0) s FROM loan_ledger WHERE loan_id = ?' . ($asOf ? ' AND entry_date <= ?' : '') . ' GROUP BY component';
        $b = ['principal' => 0, 'interest' => 0, 'penalty' => 0, 'fee' => 0];
        foreach (rows($sql, $asOf ? [$loanId, $asOf] : [$loanId]) as $r) $b[$r['component']] = (int)$r['s'];
        $b['total'] = array_sum($b);
        return $b;
    }

    public static function ledger(int $loanId, string $type, string $component, int $amount, ?string $refType = null,
                                  ?int $refId = null, string $desc = '', ?string $bd = null): int
    {
        $bd = $bd ?? posting_date((int)val('SELECT branch_id FROM loans WHERE id = ?', [$loanId]));
        return insert('loan_ledger', [
            'loan_id' => $loanId, 'business_date' => $bd, 'entry_date' => $bd, 'type' => $type, 'component' => $component,
            'amount' => $amount, 'ref_type' => $refType, 'ref_id' => $refId, 'description' => $desc ?: null,
            'user_id' => uid(), 'created_at' => now(),
        ]);
    }

    public static function setStatus(int $id, string $to, string $note = ''): void
    {
        $from = val('SELECT status FROM loans WHERE id = ?', [$id]);
        if ($from === $to) return;
        update('loans', ['status' => $to, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        insert('loan_status_history', ['loan_id' => $id, 'from_status' => $from, 'to_status' => $to, 'user_id' => uid(), 'at' => now(), 'note' => $note ?: null]);
    }

    // ---------------------------------------------------------------- application
    public static function saveApplication(array $in, ?int $id = null): int
    {
        require_perm('loan.create');
        $clientId = (int)($in['client_id'] ?? 0);
        $client = row('SELECT * FROM clients WHERE id = ?', [$clientId]);
        must((bool)$client, 'Client ID does not exist.');
        assert_active_branch((int)$client['branch_id']);
        must($client['status'] === 'Active', 'Loans can only be created for active clients (this client is ' . strtolower($client['status']) . ').');
        $amount = parse_money($in['amount_requested'] ?? '', 'Loan amount');
        $max = setting_int('requested_max_loan');
        must($max <= 0 || $amount <= $max, 'Loan amount exceeds the maximum of ' . ugx($max) . '.');
        $months = (int)($in['period_months'] ?? setting('loan_period_months'));
        must($months >= 1 && $months <= 60, 'Loan period must be between 1 and 60 months.');
        $freq = $in['frequency'] ?? setting('repayment_frequency');
        must(in_array($freq, ['daily', 'weekly', 'monthly'], true), 'Invalid repayment frequency.');
        $data = [
            'client_id' => $clientId,
            'application_date' => must_date($in['application_date'] ?? reporting_date((int)$client['branch_id']), 'Application date'),
            'purpose' => clean($in['purpose'] ?? ''),
            'amount_requested' => $amount,
            'period_months' => $months,
            'frequency' => $freq,
            'requested_disb_date' => must_date($in['requested_disb_date'] ?? '', 'Requested disbursement date', false),
            'existing_loans_info' => trim($in['existing_loans_info'] ?? '') ?: null,
            'existing_debt' => parse_money($in['existing_debt'] ?? '0', 'Existing debt', true),
            'monthly_income' => parse_money($in['monthly_income'] ?? '0', 'Monthly income', true),
            'monthly_expenses' => parse_money($in['monthly_expenses'] ?? '0', 'Monthly expenses', true),
            'security_offered' => trim($in['security_offered'] ?? '') ?: null,
            'officer_comments' => trim($in['officer_comments'] ?? '') ?: null,
        ];
        must($data['purpose'] !== '', 'Loan purpose is required.');
        return tx(function () use ($id, $data, $client) {
            if ($id) {
                $l = self::get($id);
                must(in_array($l['status'], ['Draft', 'Returned for Correction'], true), 'Only draft or returned applications can be edited.');
                must($l['officer_id'] == uid() || can('loan.approve'), 'You can only edit your own applications.');
                $dup = val("SELECT COUNT(*) FROM loans WHERE client_id=? AND amount_requested=? AND application_date=? AND id<>? AND status NOT IN ('Cancelled','Rejected')",
                    [$data['client_id'], $data['amount_requested'], $data['application_date'], $id]);
                must(!$dup, 'A matching application for this client, amount and date already exists.');
                $data['updated_at'] = now();
                update('loans', $data, 'id = :id', ['id' => $id]);
                audit('Loan application updated', 'Loans', 'loan', $id, $l['amount_requested'], $data['amount_requested']);
                return $id;
            }
            $dup = val("SELECT COUNT(*) FROM loans WHERE client_id=? AND amount_requested=? AND application_date=? AND status NOT IN ('Cancelled','Rejected')",
                [$data['client_id'], $data['amount_requested'], $data['application_date']]);
            must(!$dup, 'A matching application for this client, amount and date already exists. Open it instead of creating a duplicate.');
            $data += ['loan_no' => make_number('loan_prefix', 'loan', true), 'officer_id' => uid(), 'branch_id' => (int)$client['branch_id'], 'status' => 'Draft', 'created_at' => now()];
            $newId = insert('loans', $data);
            insert('loan_status_history', ['loan_id' => $newId, 'from_status' => null, 'to_status' => 'Draft', 'user_id' => uid(), 'at' => now(), 'note' => 'Application created']);
            audit('Loan application created', 'Loans', 'loan', $newId, null, $data['loan_no'] . ' ' . ugx($data['amount_requested']));
            return $newId;
        });
    }

    public static function submit(int $id): void
    {
        require_perm('loan.create');
        $l = self::get($id);
        must(in_array($l['status'], ['Draft', 'Returned for Correction'], true), 'Only draft or returned applications can be submitted.');
        must($l['officer_id'] == uid() || can('loan.approve'), 'You can only submit your own applications.');
        tx(function () use ($id, $l) {
            self::setStatus($id, 'Submitted', 'Submitted for review');
            audit('Loan application submitted', 'Loans', 'loan', $id, $l['status'], 'Submitted');
        });
    }

    public static function cancel(int $id, string $reason): void
    {
        $l = self::get($id);
        must(in_array($l['status'], ['Draft', 'Submitted', 'Under Review', 'Returned for Correction', 'Approved'], true), 'This loan can no longer be cancelled.');
        must($l['officer_id'] == uid() || can('loan.approve'), 'You do not have permission to perform this action.');
        must($reason !== '', 'A reason is required to cancel an application.');
        tx(function () use ($id, $l, $reason) {
            self::setStatus($id, 'Cancelled', $reason);
            audit('Loan application cancelled', 'Loans', 'loan', $id, $l['status'], 'Cancelled', $reason);
        });
    }

    // ---------------------------------------------------------------- assessment
    public static function latestAssessment(int $loanId): ?array
    {
        return row('SELECT a.*, u.full_name AS assessor FROM credit_assessments a JOIN users u ON u.id = a.assessed_by WHERE a.loan_id = ? ORDER BY a.id DESC LIMIT 1', [$loanId]);
    }

    /** Facts the assessor needs: existing loans, balances, overdue and previous defaults for this client. */
    public static function clientExposure(int $clientId, ?int $excludeLoanId = null): array
    {
        $loans = rows("SELECT l.id, l.loan_no, l.status, l.approved_principal, l.due_date, l.overdue_since, b.total_out, b.principal_out
                       FROM loans l JOIN v_loan_balances b ON b.loan_id = l.id
                       WHERE l.client_id = ? AND l.id <> ? AND l.status IN ('Active','Overdue','Closed','Disbursed')
                       ORDER BY l.id DESC", [$clientId, (int)$excludeLoanId]);
        $out = 0; $overdue = 0; $closed = 0; $late = 0;
        foreach ($loans as $r) {
            if (in_array($r['status'], self::OPEN_STATUSES, true)) $out += (int)$r['total_out'];
            if ($r['status'] === 'Overdue') $overdue++;
            if ($r['status'] === 'Closed') $closed++;
        }
        $late = (int)val("SELECT COUNT(*) FROM loans WHERE client_id = ? AND (overdue_since IS NOT NULL OR collection_status IN ('Referred for Review'))", [$clientId]);
        return ['loans' => $loans, 'outstanding' => $out, 'overdue_count' => $overdue, 'closed_count' => $closed, 'late_history' => $late];
    }

    public static function recordAssessment(int $id, array $in): void
    {
        require_perm('loan.assess');
        $l = self::get($id);
        must(in_array($l['status'], ['Submitted', 'Under Review'], true), 'Assessments can only be recorded for submitted applications.');
        $rec = $in['recommendation'] ?? '';
        must(in_array($rec, ['Approve', 'Approve with changes', 'Reject'], true), 'Please choose a recommendation.');
        $income = parse_money($in['income'] ?? '0', 'Income', true);
        $expenses = parse_money($in['expenses'] ?? '0', 'Expenses', true);
        $obl = parse_money($in['existing_obligations'] ?? '0', 'Existing obligations', true);
        $recommended = $rec === 'Reject' ? 0 : parse_money($in['recommended_amount'] ?? '', 'Recommended amount');
        tx(function () use ($id, $l, $in, $rec, $income, $expenses, $obl, $recommended) {
            $secValue = (int)val("SELECT COALESCE(SUM(est_value),0) FROM securities WHERE loan_id = ? AND status NOT IN ('Released')", [$id]);
            $aid = insert('credit_assessments', [
                'loan_id' => $id, 'assessed_by' => uid(), 'assessed_at' => now(),
                'repayment_history' => trim($in['repayment_history'] ?? '') ?: null,
                'existing_obligations' => $obl, 'income' => $income, 'expenses' => $expenses, 'disposable_income' => $income - $expenses - $obl,
                'business_details' => trim($in['business_details'] ?? '') ?: null,
                'guarantor_summary' => trim($in['guarantor_summary'] ?? '') ?: null,
                'security_value' => $secValue, 'previous_defaults' => trim($in['previous_defaults'] ?? '') ?: null,
                'recommendation' => $rec, 'recommended_amount' => $recommended, 'notes' => trim($in['notes'] ?? '') ?: null,
            ]);
            self::setStatus($id, 'Under Review', 'Credit assessment recorded');
            audit('Credit assessment recorded', 'Loans', 'loan', $id, null, ['assessment' => $aid, 'recommendation' => $rec, 'amount' => $recommended]);
        });
    }

    // ---------------------------------------------------------------- approval
    /** Default lending terms from current settings (used to pre-fill the approval form). */
    public static function defaultTerms(array $loan): array
    {
        $assess = self::latestAssessment((int)$loan['id']);
        return [
            'approved_principal' => $assess && $assess['recommended_amount'] > 0 ? (int)$assess['recommended_amount'] : (int)$loan['amount_requested'],
            'rate_bp' => pct_to_bp(setting('interest_rate_pct')),
            'processing_bp' => pct_to_bp(setting('processing_fee_pct')),
            'application_fee' => setting_int('application_fee'),
            'inspection_fee' => setting_int('inspection_fee_default'),
            'other_fees' => 0,
            'fees_deducted' => setting('fees_deducted') === '1',
            'months' => (int)$loan['period_months'],
        ];
    }

    public static function approve(int $id, array $in): void
    {
        require_perm('loan.approve');
        $l = self::get($id);
        $isAdjust = $l['status'] === 'Approved';
        must(in_array($l['status'], ['Submitted', 'Under Review'], true) || ($isAdjust && can('loan.adjust')),
            $isAdjust ? 'You do not have permission to adjust approved terms.' : 'This loan is not awaiting approval.');
        must($l['status'] !== 'Disbursed', 'This loan has already been disbursed.');
        must((int)$l['officer_id'] !== (int)uid(), 'You cannot approve a loan application that you originated.');
        $assess = self::latestAssessment($id);
        if (!$assess && setting('assessment_required') === '1') {
            must(!empty($in['exception']) && trim($in['exception_reason'] ?? '') !== '', 'A credit assessment is required before approval (or record an authorized exception reason).');
        }
        $def = self::defaultTerms($l);
        $principal = parse_money($in['approved_principal'] ?? '', 'Approved amount');
        must($principal <= (int)$l['amount_requested'], 'Approved amount cannot exceed the amount requested (' . ugx($l['amount_requested']) . ').');
        $rateBp = pct_to_bp($in['rate_pct'] ?? bp_to_pct($def['rate_bp']));
        $procBp = pct_to_bp($in['processing_pct'] ?? bp_to_pct($def['processing_bp']));
        $appFee = parse_money($in['application_fee'] ?? '0', 'Application fee', true);
        $inspFee = parse_money($in['inspection_fee'] ?? '0', 'Inspection fee', true);
        $other = parse_money($in['other_fees'] ?? '0', 'Other fees', true);
        $months = (int)($in['months'] ?? $def['months']);
        $deducted = !empty($in['fees_deducted']);
        must($inspFee >= setting_int('inspection_fee_min') && $inspFee <= setting_int('inspection_fee_max'), 'Inspection fee is outside the configured range.');
        must($other === 0 || trim($in['other_fees_note'] ?? '') !== '', 'Other deductions must be explicitly described and authorized.');
        $comments = trim($in['comments'] ?? '');
        $reason = trim($in['change_reason'] ?? '');
        $changed = $principal !== (int)$def['approved_principal'] || $rateBp !== $def['rate_bp']
            || $procBp !== $def['processing_bp'] || $appFee !== $def['application_fee'];
        must(!$changed || $reason !== '' || $isAdjust, 'A reason is required when the approved amount or standard terms differ from the recommendation/defaults.');
        must(!$isAdjust || $reason !== '', 'A reason is required to adjust approved terms.');
        $t = Calc::terms($principal, $rateBp, $months, $procBp, $appFee, $inspFee, $other, $deducted);
        $br = (int)$l['branch_id'];
        Approvals::assert('loan', $principal, $br, 'loan');          // escalation: above the Branch Manager limit only the COO may approve
        $bd = reporting_date($br);
        $penalty = [
            'enabled' => setting('penalty_enabled') === '1', 'rate_bp' => pct_to_bp(setting('penalty_rate_pct')), 'basis' => setting('penalty_basis'),
            'compound' => setting('penalty_compound') === '1', 'cap_bp' => pct_to_bp(setting('penalty_cap_pct')), 'grace_days' => setting_int('penalty_grace_days'),
        ];
        tx(function () use ($id, $l, $t, $bd, $penalty, $in, $comments, $reason, $assess, $isAdjust, $months, $deducted, $procBp) {
            $expiry = Calc::addWorkingDays($bd, setting_int('offer_validity_days'), fn($d) => is_working_day($d, (int)$l['branch_id']));
            update('loans', [
                'approved_principal' => $t['principal'], 'rate_bp' => $t['rate_bp'], 'interest_method' => setting('interest_method'),
                'interest_amount' => $t['interest'], 'processing_fee_bp' => $procBp, 'processing_fee' => $t['processing_fee'],
                'application_fee' => $t['application_fee'], 'inspection_fee' => $t['inspection_fee'], 'other_fees' => $t['other_fees'],
                'other_fees_note' => trim($in['other_fees_note'] ?? '') ?: null, 'fees_deducted' => $deducted ? 1 : 0,
                'total_fees' => $t['total_fees'], 'net_disbursement' => $t['net_disbursement'], 'total_contractual' => $t['total_contractual'],
                'period_months' => $months, 'penalty_terms' => json_encode($penalty),
                'approved_by' => uid(), 'approved_at' => now(), 'approval_date' => $bd, 'offer_expiry' => $expiry,
                'acceptance_status' => 'Pending', 'acceptance_date' => null, 'acceptance_note' => null, 'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
            self::setStatus($id, 'Approved', $isAdjust ? 'Approved terms adjusted' : 'Approved');
            insert('loan_approvals', [
                'loan_id' => $id, 'decision' => 'Approved', 'requested_amount' => $l['amount_requested'],
                'recommended_amount' => $assess['recommended_amount'] ?? null, 'approved_amount' => $t['principal'],
                'snapshot_json' => json_encode($t + ['penalty' => $penalty, 'offer_expiry' => $expiry]), 'comments' => $comments ?: null,
                'reason' => $reason ?: (trim($in['exception_reason'] ?? '') ?: null), 'approver_id' => uid(), 'approver_role' => Approvals::authority(), 'decided_at' => now(), 'business_date' => $bd,
            ]);
            audit($isAdjust ? 'Approved loan terms adjusted' : 'Loan approved', 'Loans', 'loan', $id, $isAdjust ? ugx($l['approved_principal']) : null,
                ugx($t['principal']) . ' @' . bp_to_pct($t['rate_bp']) . '%', $reason ?: $comments);
        });
    }

    public static function reject(int $id, string $reason): void
    {
        require_perm('loan.approve');
        $l = self::get($id);
        must(in_array($l['status'], ['Submitted', 'Under Review'], true), 'This loan is not awaiting approval.');
        must($reason !== '', 'A rejection reason is required.');
        tx(function () use ($id, $l, $reason) {
            update('loans', ['rejection_reason' => $reason, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            self::setStatus($id, 'Rejected', $reason);
            insert('loan_approvals', ['loan_id' => $id, 'decision' => 'Rejected', 'requested_amount' => $l['amount_requested'], 'comments' => $reason,
                'reason' => $reason, 'approver_id' => uid(), 'approver_role' => Approvals::authority(), 'decided_at' => now(), 'business_date' => reporting_date((int)$l['branch_id'])]);
            audit('Loan rejected', 'Loans', 'loan', $id, $l['status'], 'Rejected', $reason);
        });
    }

    public static function returnForCorrection(int $id, string $comments): void
    {
        require_perm('loan.approve');
        $l = self::get($id);
        must(in_array($l['status'], ['Submitted', 'Under Review'], true), 'This loan is not awaiting approval.');
        must($comments !== '', 'Please state what must be corrected.');
        tx(function () use ($id, $l, $comments) {
            self::setStatus($id, 'Returned for Correction', $comments);
            insert('loan_approvals', ['loan_id' => $id, 'decision' => 'Returned', 'requested_amount' => $l['amount_requested'], 'comments' => $comments,
                'approver_id' => uid(), 'approver_role' => Approvals::authority(), 'decided_at' => now(), 'business_date' => reporting_date((int)$l['branch_id'])]);
            audit('Loan returned for correction', 'Loans', 'loan', $id, $l['status'], 'Returned for Correction', $comments);
        });
    }

    // ---------------------------------------------------------------- offer & acceptance
    public static function offerExpired(array $l): bool
    {
        return $l['offer_expiry'] && reporting_date((int)$l['branch_id']) > $l['offer_expiry'];
    }

    public static function recordAcceptance(int $id, bool $accepted, string $date, string $note = ''): void
    {
        must(can('loan.create') || can('loan.approve') || can('loan.disburse'), 'You do not have permission to perform this action.');
        $l = self::get($id);
        must($l['status'] === 'Approved', 'This loan has not been approved.');
        must(!self::offerExpired($l), 'The loan offer expired on ' . fmt_date($l['offer_expiry']) . '. A manager must extend it before acceptance can be recorded.');
        must(is_date($date), 'Acceptance date is not valid.');
        must($date <= max(reporting_date((int)$l['branch_id']), today()), 'Acceptance date cannot be in the future.');
        tx(function () use ($id, $accepted, $date, $note, $l) {
            update('loans', ['acceptance_status' => $accepted ? 'Accepted' : 'Declined', 'acceptance_date' => $date, 'acceptance_note' => $note ?: null, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            audit($accepted ? 'Loan offer accepted by client' : 'Loan offer declined by client', 'Loans', 'loan', $id, $l['acceptance_status'], $accepted ? 'Accepted' : 'Declined', $note);
        });
    }

    /** Branch Manager hands a loan that is above their authority (or needs a second opinion) to the COO. */
    public static function escalate(int $id, string $note): void
    {
        require_perm('loan.approve');
        $l = self::get($id);
        must(in_array($l['status'], ['Submitted', 'Under Review'], true), 'This loan is not awaiting approval.');
        must($note !== '', 'Please state why the loan is being escalated.');
        tx(function () use ($id, $l, $note) {
            update('loans', ['escalated_at' => now(), 'escalated_by' => uid(), 'escalation_note' => $note, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            insert('loan_approvals', ['loan_id' => $id, 'decision' => 'Escalated', 'requested_amount' => $l['amount_requested'], 'comments' => $note, 'reason' => $note,
                'approver_id' => uid(), 'approver_role' => Approvals::authority(), 'decided_at' => now(), 'business_date' => reporting_date((int)$l['branch_id'])]);
            audit('Loan escalated to COO', 'Loans', 'loan', $id, null, 'Escalated', $note, '', null, null, (int)$l['branch_id']);
        });
    }

    public static function extendOffer(int $id, string $reason): void
    {
        require_perm('loan.approve');
        $l = self::get($id);
        must($l['status'] === 'Approved', 'Only approved, undisbursed loans can have the offer extended.');
        must($reason !== '', 'A reason is required to extend an offer.');
        $new = Calc::addWorkingDays(reporting_date((int)$l['branch_id']), setting_int('offer_validity_days'), fn($d) => is_working_day($d, (int)$l['branch_id']));
        tx(function () use ($id, $l, $new, $reason) {
            update('loans', ['offer_expiry' => $new, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            audit('Loan offer extended', 'Loans', 'loan', $id, $l['offer_expiry'], $new, $reason);
        });
    }

    // ---------------------------------------------------------------- disbursement
    public static function maturityDate(array $loan, string $disbDate): string
    {
        $base = setting('maturity_basis') === 'approval' && $loan['approval_date'] ? $loan['approval_date'] : $disbDate;
        $due = Calc::addMonths($base, (int)$loan['period_months']);
        if (setting('maturity_holiday_roll') === '1') $due = Calc::nextWorkingDay($due, 'is_working_day');
        return $due;
    }

    public static function disburse(int $id, array $in): int
    {
        require_perm('loan.disburse');
        $l = self::get($id);
        must(!in_array($l['status'], ['Active', 'Overdue', 'Closed', 'Disbursed'], true) && !val('SELECT COUNT(*) FROM disbursements WHERE loan_id = ?', [$id]), 'This loan has already been disbursed.');
        must($l['status'] === 'Approved', 'This loan has not been approved.');
        must($l['acceptance_status'] === 'Accepted', 'Loan offer acceptance is required before disbursement.');
        must(!self::offerExpired($l), 'The loan offer has expired and must be reviewed by a manager before disbursement.');
        must(!empty($in['doc_verified']), 'Please confirm that the required documentation has been verified.');
        must(!empty($in['client_ack']), 'Client acknowledgment of receipt is required.');
        $method = $in['method'] ?? 'Cash';
        must(in_array($method, ['Cash', 'Bank Transfer', 'Mobile Money', 'Other'], true), 'Invalid payment method.');
        $ref = trim($in['reference'] ?? '');
        must($method === 'Cash' || $ref !== '', 'A transaction reference is required for non-cash disbursements.');
        $br = (int)$l['branch_id'];
        assert_active_branch($br);
        $authId = (int)($in['authorized_by'] ?? 0) ?: (can('loan.disburse.authorize') ? uid() : 0);
        must($authId > 0 && in_array('loan.disburse.authorize', user_permissions($authId), true), 'Select an authorizing manager.');
        must($authId !== (int)$l['officer_id'], 'Separation of duties: the loan officer who originated the loan cannot authorize its disbursement.');
        must($authId !== (int)uid() || (int)uid() !== (int)$l['officer_id'], 'You cannot authorize a disbursement on your own application.');
        $needAuth = Approvals::required('disbursement', (int)$l['approved_principal'], $br);
        must(Approvals::covers(Approvals::authority($authId), $needAuth), 'This disbursement of ' . ugx($l['approved_principal']) . ' exceeds the Branch Manager limit and must be authorized by the COO.');

        return tx(function () use ($id, $l, $in, $method, $ref, $authId, $br) {
            $bd = posting_date($br);
            $client = row('SELECT status FROM clients WHERE id = ?', [$l['client_id']]);
            must($client['status'] !== 'Blacklisted', 'This client is blacklisted.');
            $net = (int)$l['net_disbursement'];
            $session = null;
            if ($method === 'Cash') {
                $session = Cash::requireOpenSession();
                $avail = Cash::expected((int)$session['id']);
                must($avail >= $net, 'The till has insufficient cash (' . ugx($avail) . ') to release ' . ugx($net) . '. Transfer cash into the till first.');
            }
            $due = self::maturityDate($l, $bd);
            $ded = (bool)$l['fees_deducted'];
            $did = insert('disbursements', [
                'branch_id' => $br, 'disb_no' => make_number('loan_prefix', 'disb', true), 'loan_id' => $id, 'client_id' => $l['client_id'],
                'approved_principal' => $l['approved_principal'],
                'processing_fee' => $ded ? $l['processing_fee'] : 0, 'application_fee' => $ded ? $l['application_fee'] : 0,
                'inspection_fee' => $ded ? $l['inspection_fee'] : 0, 'other_deductions' => $ded ? $l['other_fees'] : 0,
                'net_amount' => $net, 'disbursement_date' => $bd, 'business_date' => $bd, 'method' => $method, 'reference' => $ref ?: null,
                'session_id' => $session['id'] ?? null, 'disbursed_by' => uid(), 'authorized_by' => $authId,
                'client_ack' => 1, 'doc_verified' => 1, 'notes' => trim($in['notes'] ?? '') ?: null, 'created_at' => now(),
            ]);
            // contractual amounts become receivable
            self::ledger($id, 'disbursement', 'principal', (int)$l['approved_principal'], 'disbursement', $did, 'Loan principal disbursed', $bd);
            self::ledger($id, 'interest', 'interest', (int)$l['interest_amount'], 'disbursement', $did, 'Contractual interest (' . bp_to_pct((int)$l['rate_bp']) . '% flat)', $bd);
            if (!$ded && (int)$l['total_fees'] > 0) self::ledger($id, 'fee', 'fee', (int)$l['total_fees'], 'disbursement', $did, 'Fees payable separately', $bd);
            // cash / bank movement of the NET amount actually released
            Cash::record(['session' => $session['id'] ?? null, 'direction' => -1, 'amount' => $net, 'category' => 'disbursement', 'method' => $method,
                'ref_type' => 'disbursement', 'ref_id' => $did, 'reference' => $ref ?: $l['loan_no'], 'description' => 'Loan disbursement ' . $l['loan_no'], 'bd' => $bd, 'branch' => $br]);
            // repayment schedule
            foreach (Calc::schedule((int)$l['approved_principal'], (int)$l['interest_amount'], $bd, $due, $l['frequency'], setting('freq_mode')) as $s) {
                insert('repayment_schedules', ['loan_id' => $id, 'version' => 1, 'installment_no' => $s['no'], 'due_date' => $s['due_date'],
                    'principal_due' => $s['principal'], 'interest_due' => $s['interest'], 'total_due' => $s['total'], 'status' => 'Current', 'created_at' => now()]);
            }
            update('loans', ['disbursement_date' => $bd, 'due_date' => $due, 'disbursed_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $id]);
            self::setStatus($id, 'Active', 'Loan disbursed');
            audit('Loan disbursed', 'Disbursements', 'loan', $id, null, ['net' => $net, 'principal' => $l['approved_principal'], 'method' => $method, 'disbursement' => $did]);
            return $did;
        });
    }

    // ---------------------------------------------------------------- tracking
    public static function schedule(int $loanId): array
    {
        return rows("SELECT * FROM repayment_schedules WHERE loan_id = ? AND status = 'Current' ORDER BY installment_no", [$loanId]);
    }

    /** Schedule rows annotated with how much of each instalment has been paid (earliest first). */
    public static function scheduleWithPaid(array $loan): array
    {
        $sched = self::schedule((int)$loan['id']);
        $b = self::balances((int)$loan['id']);
        $assessed = (int)$loan['approved_principal'] + (int)$loan['interest_amount'];
        $paidPI = max(0, $assessed - $b['principal'] - $b['interest']);
        $left = $paidPI;
        $cum = 0;
        $lastPay = null;
        foreach ($sched as &$s) {
            $take = min($left, (int)$s['total_due']);
            $s['paid'] = $take;
            $s['remaining'] = (int)$s['total_due'] - $take;
            $left -= $take;
            $cum += (int)$s['total_due'];
            $s['cumulative'] = $cum;
        }
        unset($s);
        return $sched;
    }

    /** @return array{days:int,since:?string,amount:int} */
    public static function overdueInfo(array $loan, ?string $asOf = null): array
    {
        $asOf = $asOf ?? reporting_date((int)$loan['branch_id']);
        if (!in_array($loan['status'], self::OPEN_STATUSES, true)) return ['days' => 0, 'since' => null, 'amount' => 0];
        $b = self::balances((int)$loan['id'], null);
        $sched = self::scheduleWithPaid($loan);
        $since = null; $amount = 0;
        foreach ($sched as $s) {
            if ($s['remaining'] > 0 && $s['due_date'] < $asOf) {
                $since = $since ?? $s['due_date'];
                $amount += $s['remaining'];
            }
        }
        if ($since === null && $b['fee'] > 0 && $loan['due_date'] && $loan['due_date'] < $asOf) { $since = $loan['due_date']; $amount = $b['fee']; }
        return ['days' => $since ? days_between($since, $asOf) : 0, 'since' => $since, 'amount' => $amount];
    }

    /** Recompute Active/Overdue status, overdue-since date and automatic collection status for open loans. */
    public static function refreshStatuses(?int $onlyLoan = null, ?int $branch = null): void
    {
        $sql = "SELECT * FROM loans WHERE status IN ('Active','Overdue')" . ($onlyLoan ? ' AND id = ' . (int)$onlyLoan : '') . ($branch ? ' AND branch_id = ' . (int)$branch : '');
        foreach (rows($sql) as $l) {
            $asOf = reporting_date((int)$l['branch_id']);
            $info = self::overdueInfo($l, $asOf);
            $newStatus = $info['since'] ? 'Overdue' : 'Active';
            $cs = $l['collection_status'];
            $auto = ['Not Due', 'Due Today', 'Overdue', 'Settled', ''];
            if ($info['since']) {
                if (in_array($cs, $auto, true)) $cs = 'Overdue';
            } else {
                $cs = ($l['due_date'] === $asOf) ? 'Due Today' : 'Not Due';
            }
            if ($newStatus !== $l['status'] || $info['since'] !== $l['overdue_since'] || $cs !== $l['collection_status']) {
                update('loans', ['overdue_since' => $info['since'], 'collection_status' => $cs, 'updated_at' => now()], 'id = :id', ['id' => $l['id']]);
                if ($newStatus !== $l['status']) self::setStatus((int)$l['id'], $newStatus, 'Status refreshed on ' . $asOf);
            }
        }
    }

    // ---------------------------------------------------------------- closure & waivers
    public static function isSettled(int $loanId): bool
    {
        $b = self::balances($loanId);
        return $b['principal'] <= 0 && $b['interest'] <= 0 && $b['penalty'] <= 0 && $b['fee'] <= 0;
    }

    /** Close the loan automatically once everything due is settled (SRS 24.2). */
    public static function autoClose(int $loanId, string $finalRef = ''): bool
    {
        $l = self::get($loanId);
        if (!in_array($l['status'], self::OPEN_STATUSES, true) || !self::isSettled($loanId)) return false;
        $unalloc = (int)val("SELECT COALESCE(SUM(remaining),0) FROM customer_credits WHERE loan_id = ? AND status = 'Open'", [$loanId]);
        $bd = posting_date((int)$l['branch_id']);
        $waivers = (int)val("SELECT COALESCE(SUM(amount),0) FROM loan_adjustments WHERE loan_id = ? AND type LIKE '%Waiver%'", [$loanId]);
        tx(function () use ($loanId, $l, $bd, $finalRef, $waivers, $unalloc) {
            insert('loan_closures', [
                'loan_id' => $loanId, 'client_id' => $l['client_id'], 'closure_date' => $bd, 'final_payment_ref' => $finalRef ?: null,
                'final_principal' => 0, 'final_interest' => 0, 'final_penalty' => 0, 'waivers' => $waivers, 'closed_by' => uid(),
                'statement' => 'Loan fully settled' . ($unalloc ? '; unallocated customer credit of ' . ugx($unalloc) . ' remains' : ''), 'created_at' => now(),
            ]);
            update('loans', ['closed_date' => $bd, 'overdue_since' => null, 'collection_status' => 'Settled', 'updated_at' => now()], 'id = :id', ['id' => $loanId]);
            self::setStatus($loanId, 'Closed', 'Fully settled');
            audit('Loan closed', 'Loans', 'loan', $loanId, $l['status'], 'Closed', 'Fully settled', $finalRef);
        });
        return true;
    }

    /** Re-open a closed loan if a reversal/adjustment made it owe money again. */
    public static function reopenIfOwing(int $loanId, string $why): void
    {
        $l = self::get($loanId);
        if ($l['status'] === 'Closed' && !self::isSettled($loanId)) {
            update('loans', ['closed_date' => null, 'collection_status' => 'Not Due', 'updated_at' => now()], 'id = :id', ['id' => $loanId]);
            self::setStatus($loanId, 'Active', $why);
            audit('Loan re-opened', 'Loans', 'loan', $loanId, 'Closed', 'Active', $why);
            self::refreshStatuses($loanId);
        }
    }

    public static function manualClose(int $loanId, string $note): void
    {
        require_perm('loan.close');
        $l = self::get($loanId);
        must(in_array($l['status'], self::OPEN_STATUSES, true), 'Only active loans can be closed.');
        must(self::isSettled($loanId), 'The loan still has outstanding amounts. Collect or waive them (with authorization) before closing.');
        self::autoClose($loanId, $note);
    }

    /** Authorized waiver of an outstanding component. Posted as a negative ledger entry, never as an edit. */
    public static function waive(int $loanId, string $component, int $amount, string $reason): void
    {
        must(in_array($component, ['penalty', 'interest', 'fee', 'principal'], true), 'Invalid component.');
        if ($component === 'penalty') must(can('penalty.adjust') || can('loan.close'), 'You do not have permission to perform this action.');
        else require_perm('loan.close');
        must($reason !== '', 'A reason is required for a waiver.');
        must($amount > 0, 'Waiver amount must be greater than zero.');
        $l = self::get($loanId);
        must(in_array($l['status'], self::OPEN_STATUSES, true), 'Waivers can only be applied to active loans.');
        assert_active_branch((int)$l['branch_id']);
        Approvals::assert('waiver', $amount, (int)$l['branch_id'], 'waiver');
        tx(function () use ($loanId, $component, $amount, $reason, $l) {
            $b = self::balances($loanId);
            must($amount <= $b[$component], 'Waiver exceeds the outstanding ' . $component . ' (' . ugx($b[$component]) . ').');
            $bd = posting_date((int)$l['branch_id']);
            insert('loan_adjustments', ['loan_id' => $loanId, 'type' => ucfirst($component) . ' Waiver', 'component' => $component, 'amount' => $amount,
                'reason' => $reason, 'approved_by' => uid(), 'created_by' => uid(), 'business_date' => $bd, 'created_at' => now()]);
            self::ledger($loanId, 'waiver', $component, -$amount, 'adjustment', null, 'Waiver: ' . $reason, $bd);
            audit(ucfirst($component) . ' waived', 'Loans', 'loan', $loanId, ugx($b[$component]), ugx($b[$component] - $amount), $reason);
            self::autoClose($loanId, 'Waiver');
        });
    }
}
