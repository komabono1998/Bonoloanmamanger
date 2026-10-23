<?php
$title = 'End of Day';
$a = get('a');
if ((int)get('branch') && in_array((int)get('branch'), accessible_branch_ids(), true) && (int)get('branch') !== branch_id()) set_branch_context((int)get('branch'));
$bd = current_bd();

if ($a === 'open') post_action(function () {
    Eod::openDay(post('date'), (bool)post('allow_nonworking'));
    return 'Business day ' . fmt_date(post('date')) . ' is open.';
});
if ($a === 'submit') post_action(function () use ($bd) {
    must($bd !== null, 'No business day is open.');
    Eod::submit($bd, clean(post('notes')));
    return 'End-of-Day submitted for manager review.';
});
if ($a === 'approve') post_action(function () use ($bd) {
    must($bd !== null, 'No business day is open.');
    $b = Eod::approve($bd, clean(post('notes')), null, !empty($_POST['backup_first']));
    $next = !empty($b['next_date']) ? ' The system date is now ' . fmt_date($b['next_date']) . '.' : '';
    if ($b['ok']) return 'Business date approved and locked. Verified backup created: ' . $b['file'] . '.' . $next;
    flash('error', 'The business date is closed' . ($next ? ' (the system date is now ' . fmt_date($b['next_date']) . ')' : '') . ', but the backup FAILED: ' . $b['message'] . ' Run a manual backup immediately.');
    return null;
});
if ($a === 'return') post_action(function () use ($bd) {
    Eod::returnForInvestigation($bd, clean(post('notes')));
    return 'Returned for investigation.';
});
if ($a === 'reopen_request') post_action(function () {
    Eod::requestReopen(post('date'), clean(post('reason')));
    return 'Reopen request sent for manager authorization.';
});
if ($a === 'reopen_decide') post_action(function () {
    Eod::decideReopen((int)post('request_id'), post('decision') === 'approve', clean(post('notes')));
    return post('decision') === 'approve' ? 'Business date reopened by authorization. It must be reconciled and approved again.' : 'Reopen request rejected.';
});

page_head('End of Day — ' . branch_name(branch_id()), '', 'Each branch runs its own business date. Finance prepares; an independent Branch Manager (or the COO, when a cash variance exceeds the limit) approves and locks it.');
$viewDate = get('date');

// ---------------------------------------------------------------- historical record view
if ($viewDate && is_date($viewDate) && $viewDate !== $bd) {
    $recs = rows('SELECT e.*, s.full_name AS sub, r.full_name AS rev FROM eod_records e LEFT JOIN users s ON s.id = e.submitted_by LEFT JOIN users r ON r.id = e.reviewed_by WHERE e.branch_id = ? AND e.business_date = ? ORDER BY e.version DESC', [branch_id(), $viewDate]);
    echo '<div class="card"><div class="card-h"><h2>EOD records for ' . e(fmt_date($viewDate)) . '</h2>' . btn('Back', url('eod'), 'sm') . btn('Print closure summary', url('print', ['doc' => 'eod', 'date' => $viewDate]), 'sm') . '</div>';
    data_table(['version' => ['Version', 'num'], 'status' => ['Status', 'badge'], 'submitted_at' => ['Submitted', 'dt'], 'sub' => 'Submitted by', 'reviewed_at' => ['Reviewed', 'dt'], 'rev' => 'Reviewed by', 'review_notes' => 'Review notes', 'backup_status' => 'Backup'],
        $recs, ['class' => 'nofilter', 'empty' => 'No EOD record for this date.']);
    echo '</div>';
    if ($recs) { $sum = json_decode($recs[0]['summary_json'], true); echo '<h2>Snapshot (latest version)</h2>'; require BASE_PATH . '/app/views/eod_summary.php'; }
    $rq = rows('SELECT q.*, u.full_name AS req, d.full_name AS dec FROM eod_reopen_requests q JOIN users u ON u.id = q.requested_by LEFT JOIN users d ON d.id = q.decided_by WHERE q.branch_id = ? AND q.business_date = ? ORDER BY q.id', [branch_id(), $viewDate]);
    if ($rq) { echo '<div class="card"><h2>Reopen history</h2>'; data_table(['requested_at' => ['Requested', 'dt'], 'req' => 'By', 'reason' => 'Reason', 'status' => ['Status', 'badge'], 'dec' => 'Decided by', 'decision_notes' => 'Notes'], $rq, ['class' => 'nofilter']); echo '</div>'; }
    return;
}

// ---------------------------------------------------------------- pending reopen requests
$pending = rows("SELECT q.*, u.full_name AS req FROM eod_reopen_requests q JOIN users u ON u.id = q.requested_by WHERE q.status = 'Pending' AND q.branch_id = " . (int)branch_id());
if ($pending && can('eod.approve')) {
    foreach ($pending as $q) {
        echo '<div class="card"><h2>Reopen request — ' . e(fmt_date($q['business_date'])) . '</h2><p><b>' . e($q['req']) . '</b> asked on ' . e(fmt_dt($q['requested_at'])) . ': ' . e($q['reason']) . '</p>';
        form_open(url('eod', ['a' => 'reopen_decide']), ['attrs' => 'style="grid-template-columns:2fr auto auto;align-items:end"']);
        echo '<input type="hidden" name="request_id" value="' . (int)$q['id'] . '">';
        field('notes', 'Decision notes', []);
        echo '<button class="btn primary" name="decision" value="approve" data-confirm="Reopen this business date? The original closing record is preserved.">Authorize reopening</button><button class="btn danger" name="decision" value="reject">Reject</button>';
        form_close();
        echo '</div>';
    }
}

// ---------------------------------------------------------------- no open day
if (!$bd) {
    $last = val('SELECT MAX(business_date) FROM business_days WHERE branch_id = ?', [branch_id()]);
    if (can('eod.manage')) {
        echo '<div class="card"><h2>Start a new business day</h2>';
        echo $last ? '<p class="muted">Last closed business date: <b>' . e(fmt_date($last)) . '</b>.</p>' : '<p class="muted">No business day has been opened yet.</p>';
        form_open(url('eod', ['a' => 'open']));
        field('date', 'Business date', ['type' => 'date', 'value' => Eod::suggestNextDate(), 'required' => true, 'help' => 'The business date is separate from the computer clock (' . fmt_date(today()) . ').']);
        field('allow_nonworking', 'Open even if not a working day', ['type' => 'checkbox']);
        echo '<div class="form-actions">'; submit_btn('Start business day'); echo '</div>'; form_close(); echo '</div>';
    } else echo '<div class="notice">No business day is open. A manager must start the day.</div>';
    if ($last && can('eod.reopen')) {
        $lastDay = Eod::day($last);
        if ($lastDay && $lastDay['status'] === 'Closed') {
            echo '<details class="card"><summary>Request to reopen ' . e(fmt_date($last)) . ' (correction after closure)</summary><p class="muted">A manager must authorize. The original closing record is kept, every later change is audited, and the date must be reconciled and approved again.</p>';
            form_open(url('eod', ['a' => 'reopen_request']));
            field('date', '', ['type' => 'hidden', 'value' => $last]); field('reason', 'Reason for reopening', ['span' => 3, 'required' => true]);
            echo '<div class="form-actions">'; submit_btn('Send request', 'default'); echo '</div>'; form_close(); echo '</details>';
        }
    }
} else {
    // ---------------------------------------------------------------- open day workspace
    $status = Eod::status($bd);
    $rec = Eod::latestRecord($bd);
    $day = Eod::day($bd);
    echo '<div class="kpis">' . kpi('Business date', e(fmt_date($bd)), $day['status'] === 'Reopened' ? 'reopened by authorization' : 'opened ' . fmt_dt($day['opened_at'])) . kpi('EOD status', badge($status))
        . kpi('Opened by', e(val('SELECT full_name FROM users WHERE id = ?', [$day['opened_by']]))) . '</div>';

    echo '<div class="grid g2"><div class="card"><h2>Checklist</h2>';
    foreach (Eod::checklist($bd) as $i) {
        echo '<div class="check"><span class="' . ($i['ok'] ? 'ico-ok' : ($i['blocking'] ? 'ico-bad' : 'ico-warn')) . '">' . ($i['ok'] ? '✔' : ($i['blocking'] ? '✖' : '!')) . '</span><div><b>' . e($i['label']) . '</b><br><span class="muted">' . e($i['detail']) . '</span></div></div>';
    }
    echo '</div><div class="card"><h2>Cashier sessions</h2>';
    $ss = rows('SELECT s.*, u.full_name AS cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.branch_id = ? AND s.business_date = ? ORDER BY s.id', [branch_id(), $bd]);
    foreach ($ss as &$r) if ($r['status'] === 'Open') $r['expected_cash'] = Cash::expected((int)$r['id']);
    unset($r);
    data_table(['session_no' => 'Session', 'cashier' => 'Cashier', 'expected_cash' => ['Expected', 'money'], 'counted_cash' => ['Counted', 'money'], 'variance' => ['Variance', 'money'], 'status' => ['Status', 'badge']], $ss,
        ['link' => fn($r) => url('till', ['tab' => 'sessions', 'session' => $r['id']]), 'class' => 'nofilter', 'empty' => 'No tills opened yet.']);
    echo '</div></div>';

    if ($status === 'Awaiting Manager Review' && $rec) {
        echo '<div class="card"><h2>' . ($rec['status'] === 'Escalated to COO' ? 'Escalated to the COO' : 'Awaiting manager approval') . '</h2>' . ($rec['status'] === 'Escalated to COO' ? '<div class="notice bad">The cash variance (' . ugx(Eod::absVariance($bd)) . ') exceeds the Branch Manager limit. Only the COO can approve this End-of-Day.</div>' : '') . '<p>Submitted by ' . e(val('SELECT full_name FROM users WHERE id = ?', [$rec['submitted_by']])) . ' on ' . e(fmt_dt($rec['submitted_at'])) . ($rec['notes'] ? ' — ' . e($rec['notes']) : '') . '. Version ' . (int)$rec['version'] . '.</p>';
        if (can('eod.approve')) {
            echo '<div class="grid g2"><div>';
            form_open(url('eod', ['a' => 'approve']), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Approve and LOCK this business date? Normal posting to it will stop and a backup will be taken."']);
            field('notes', 'Approval notes', ['type' => 'textarea']);
            echo '<div class="fld"><label class="chk"><input type="checkbox" name="backup_first" value="1"' . (setting('eod_pre_backup') === 'always' ? ' checked disabled' : ' checked') . '> Take a verified backup <b>before</b> closing</label><small class="muted">' . (setting('eod_pre_backup') === 'always' ? 'Required by policy. ' : '') . 'If the backup fails, the day is not closed. A second backup is taken after closing.</small></div>';
            echo '<div class="form-actions">'; submit_btn('Approve & lock business date', 'primary'); echo '</div>'; form_close();
            echo '</div><div>';
            form_open(url('eod', ['a' => 'return']), ['attrs' => 'style="grid-template-columns:1fr"']);
            field('notes', 'Return for investigation — what is wrong?', ['type' => 'textarea', 'required' => true]); echo '<div class="form-actions">'; submit_btn('Return for investigation', 'danger'); echo '</div>'; form_close();
            echo '</div></div>';
        } else echo '<p class="muted">Only an authorized manager can approve.</p>';
        echo '</div>';
    } elseif (can('eod.manage')) {
        if ($status === 'Variance Investigation' && $rec && $rec['status'] === 'Variance Investigation') echo '<div class="notice bad">The manager returned the day: ' . e($rec['review_notes']) . '</div>';
        $blk = Eod::blockers($bd);
        echo '<div class="card"><h2>Submit for manager review</h2>' . ($blk ? '<div class="notice bad">Cannot submit yet: ' . e(implode('; ', array_map(fn($b) => $b['label'] . ' — ' . $b['detail'], $blk))) . '.</div>' : '<p class="muted">All tills are reconciled. Submitting accrues any due penalties, refreshes loan statuses and snapshots the day.</p>');
        form_open(url('eod', ['a' => 'submit']), ['attrs' => 'style="grid-template-columns:1fr"']);
        field('notes', 'Notes for the manager', ['type' => 'textarea']); echo '<div class="form-actions"><button class="btn primary" ' . ($blk ? 'disabled' : '') . '>Submit End-of-Day</button></div>'; form_close(); echo '</div>';
    }

    $exc = Eod::exceptions($bd);
    echo '<div class="card"><h2>Exceptions & items for review</h2>';
    data_table(['type' => 'Type', 'ref' => 'Reference', 'detail' => 'Detail'], $exc, ['class' => 'nofilter', 'empty' => 'No exceptions.']);
    echo '</div>';
    echo '<h2 style="margin:1.2rem 0 .6rem">Live summary for ' . e(fmt_date($bd)) . '</h2>';
    $sum = Eod::summary($bd);
    require BASE_PATH . '/app/views/eod_summary.php';
}

// ---------------------------------------------------------------- history
echo '<div class="card"><h2>Business-day history</h2>';
$hist = rows("SELECT d.business_date, d.status, d.opened_at, d.closed_at, ou.full_name AS opener, cu.full_name AS closer,
              (SELECT version FROM eod_records e WHERE e.branch_id = d.branch_id AND e.business_date = d.business_date ORDER BY version DESC LIMIT 1) AS version,
              (SELECT backup_status FROM eod_records e WHERE e.branch_id = d.branch_id AND e.business_date = d.business_date ORDER BY version DESC LIMIT 1) AS backup,
              (SELECT COALESCE(SUM(variance),0) FROM cashier_sessions s WHERE s.branch_id = d.branch_id AND s.business_date = d.business_date) AS variance
              FROM business_days d LEFT JOIN users ou ON ou.id = d.opened_by LEFT JOIN users cu ON cu.id = d.closed_by WHERE d.branch_id = " . (int)branch_id() . " ORDER BY d.business_date DESC LIMIT 60");
data_table(['business_date' => ['Business date', 'date'], 'status' => ['Status', 'badge'], 'opener' => 'Opened by', 'closed_at' => ['Closed', 'dt'], 'closer' => 'Closed by', 'version' => ['Version', 'num'], 'variance' => ['Cash variance', 'money'], 'backup' => 'EOD backup'],
    $hist, ['link' => fn($r) => url('eod', ['date' => $r['business_date']]), 'empty' => 'No business days yet.', 'class' => 'nofilter']);
echo '</div>';
