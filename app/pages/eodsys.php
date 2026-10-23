<?php
/**
 * End of Day: one step, one place, one person. The System Administrator backs up and then runs End of Day for each branch.
 * Closing the day locks the business date and moves the system date on to the next working day.
 */
$title = 'End of Day';
$branches = all_branch_options(false);

if (get('a') === 'backup') post_action(function () use ($branches) {
    require_perm('backup.manage');
    $bid = (int)post('branch');
    must(isset($branches[$bid]), 'Choose a branch.');
    $bd = current_bd($bid);
    must($bd !== null, 'This branch has no open business day to back up for.');
    $b = Backup::create('Pre-EOD', $bd);
    must($b['ok'], 'The backup failed: ' . $b['message']);
    return 'Verified backup taken for ' . branch_name($bid) . ' — ' . fmt_date($bd) . '. You can now run End of Day.';
});
if (get('a') === 'run') post_action(function () use ($branches) {
    require_perm('eod.run');
    $bid = (int)post('branch');
    must(isset($branches[$bid]), 'Choose a branch.');
    must(!empty($_POST['confirm']), 'Tick the confirmation to run End of Day.');
    $bd = current_bd($bid);
    must($bd !== null, 'This branch has no open business day.');
    $r = Eod::runDay($bd, clean(post('notes')), $bid);
    $msg = 'End of Day complete for ' . branch_name($bid) . ': ' . fmt_date($bd) . ' is closed and locked' . ($r['next_date'] ? '; the system date is now ' . fmt_date($r['next_date']) . '.' : '; start the next business day when ready.');
    if (!$r['ok']) { flash('error', $msg . ' The closing backup FAILED: ' . $r['message'] . ' Take a manual backup now.'); return null; }
    return $msg . ' Closing backup: ' . $r['file'];
});
if (get('a') === 'startday') post_action(function () use ($branches) {
    require_perm('eod.run');
    $bid = (int)post('branch');
    must(isset($branches[$bid]), 'Choose a branch.');
    Eod::startDay(post('date'), !empty($_POST['allow_nonworking']), $bid);
    return 'Business day ' . fmt_date(post('date')) . ' is open for ' . branch_name($bid) . '.';
});

if (get('a') === 'step' && is_post()) {   // one phase of End of Day, answered as JSON so the screen can show progress
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    try {
        csrf_check();
        require_perm('eod.run');
        must(Eod::adminMode(), 'End of Day by the administrator is switched off in Settings.');
        $bid = (int)post('branch');
        must(isset($branches[$bid]), 'Choose a branch.');
        $date = post('date');
        must(is_date($date), 'Business date is not valid.');
        $ph = post('phase');
        if ($ph === 'done') {
            $nx = current_bd($bid);
            Eod::notifyClosed($bid, $date, $nx, post('backup_ok') !== '0');
            echo json_encode(['ok' => true, 'message' => 'Notice sent to the branch manager and the COO.', 'next_date' => $nx ? fmt_date($nx) : '']);
        } else {
            must(isset(Eod::PHASES[$ph]), 'Unknown step.');
            $r = Eod::runPhase($ph, $date, clean(post('notes')), $bid);
            echo json_encode(['ok' => (bool)($r['ok'] ?? true), 'message' => (string)($r['message'] ?? '')]);
        }
    } catch (AppError $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'The step could not be completed. Nothing further was run; try again or contact support.']);
    }
    exit;
}

page_head('End of Day', '', 'One click closes the day: it backs up, closes and locks the business date, posts to the ledger and moves the system date to the next working day.');
if (!Eod::adminMode()) echo '<div class="notice">This institution uses the two-person workflow (finance prepares, a manager approves). The one-step process is switched off in Settings → Cash &amp; End of Day.</div>';
if (!$branches) echo '<div class="card"><p class="muted">No branches yet.</p></div>';

foreach ($branches as $bid => $bn) {
    $bd = current_bd((int)$bid);
    $last = val("SELECT MAX(business_date) FROM business_days WHERE branch_id = ? AND status = 'Closed'", [$bid]);
    echo '<div class="card eod-card"><div class="card-h"><h2>' . icon('building', 18) . ' ' . e($bn) . '</h2><span class="sysdate-pill">' . icon('calendar', 14) . ' System date <b>' . ($bd ? e(fmt_date($bd)) : 'no day open') . '</b></span></div>';
    if (!$bd) {
        echo '<p class="muted">' . ($last ? 'Last closed business date: <b>' . e(fmt_date($last)) . '</b>.' : 'No business day has been opened yet.') . ' Start the next day to open the branch for work.</p>';
        form_open(url('eodsys', ['a' => 'startday']), ['attrs' => 'style="grid-template-columns:1fr 1fr auto;align-items:end"']);
        field('branch', '', ['type' => 'hidden', 'value' => $bid]);
        field('date', 'Business date', ['type' => 'date', 'value' => Eod::suggestNextDate((int)$bid), 'required' => true]);
        field('allow_nonworking', 'Open even if not a working day', ['type' => 'checkbox']);
        echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Start business day'); echo '</div>'; form_close();
        echo '</div>';
        continue;
    }
    $blockers = Eod::blockers($bd, (int)$bid);
    $canRun = can('eod.run') && Eod::adminMode();
    echo '<div class="eod-go" data-eod data-branch="' . (int)$bid . '" data-date="' . e($bd) . '" data-datelabel="' . e(fmt_date($bd)) . '" data-url="' . e(url('eodsys', ['a' => 'step'])) . '" data-csrf="' . e(csrf_token()) . '" data-branchname="' . e($bn) . '">';
    echo '<div class="eod-ready">';
    if ($blockers) {
        echo '<div class="eod-wait"><b>Not ready to close yet</b><ul>';
        foreach ($blockers as $b) echo '<li>' . e($b['label']) . ' <span class="muted">&mdash; ' . e($b['detail']) . '</span></li>';
        echo '</ul><a class="btn sm" href="' . e(url('till')) . '">Open Cashier &amp; Till</a></div>';
    } else {
        echo '<p>The branch is ready: every till is counted and reconciled. Running End of Day backs up the database, closes and locks <b>' . e(fmt_date($bd)) . '</b>, posts the day to the ledger and opens the next working day.</p>';
    }
    if ($canRun) {
        echo '<details class="eod-note"><summary>Add a note (optional)</summary><textarea data-eod-notes rows="2" placeholder="Anything worth recording about today"></textarea></details>';
        echo '<button type="button" class="btn primary eod-btn" data-eod-start' . ($blockers ? ' disabled title="Close and count every till first"' : '') . '>' . icon('check', 18) . ' Run End of Day for ' . e(fmt_date($bd)) . '</button>';
        if ($blockers) echo '<p class="muted" style="margin:.4rem 0 0;font-size:.82rem">The button unlocks once every till is closed and reconciled.</p>';
    } else echo '<p class="muted">You do not have permission to run End of Day.</p>';
    echo '</div><div class="eod-run" hidden><div class="eod-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-eod-fill></span></div>'
        . '<div class="eod-pct" data-eod-pct>0%</div><ol class="eod-list">';
    foreach (Eod::PHASES as $k => $label) echo '<li data-phase="' . e($k) . '"><span class="eod-ic"></span><span class="eod-lb">' . e($label) . '</span><small data-msg></small></li>';
    echo '<li data-phase="done"><span class="eod-ic"></span><span class="eod-lb">Sending the completion notice</span><small data-msg></small></li>';
    echo '</ol><div class="eod-result" data-eod-result hidden></div></div></div></div>';
}
echo '<div class="eod-modal" data-eod-modal hidden role="dialog" aria-modal="true" aria-labelledby="eod-q"><div class="eod-dialog"><h2 id="eod-q">Run End of Day?</h2>'
    . '<p data-eod-question></p><p class="muted">The business date will be closed and locked, and the system date moves to the next working day. This cannot be undone without a manager reopening the date.</p>'
    . '<div class="eod-dialog-actions"><button type="button" class="btn" data-eod-no>No, not yet</button><button type="button" class="btn primary" data-eod-yes>Yes, run End of Day</button></div></div></div>';
echo '<script src="' . e(asset('eod_run.js')) . '"></script>';

echo '<div class="card"><h2>End-of-Day history</h2>';
data_table(['branch' => 'Branch', 'business_date' => ['Business date', 'date'], 'status' => ['Status', 'badge'], 'closed_at' => ['Closed', 'dt'], 'closer' => 'Closed by', 'backup' => 'Closing backup'],
    rows("SELECT br.name AS branch, d.business_date, d.status, d.closed_at, u.full_name AS closer,
          (SELECT e.backup_status FROM eod_records e WHERE e.branch_id = d.branch_id AND e.business_date = d.business_date ORDER BY e.version DESC LIMIT 1) AS backup
          FROM business_days d JOIN branches br ON br.id = d.branch_id LEFT JOIN users u ON u.id = d.closed_by ORDER BY d.business_date DESC, br.name LIMIT 30"), ['class' => 'nofilter', 'empty' => 'No business days yet.']);
echo '</div>';
