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

page_head('End of Day', '', 'One step, one place, one person: back up, then run End of Day. Closing locks the business date and moves the system date to the next working day.');
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
    $pre = Eod::lastPreBackup($bd);
    echo '<ol class="eod-steps">';
    // step 0: readiness (tills reconciled)
    echo '<li class="' . ($blockers ? 'todo' : 'done') . '"><b>Branch ready</b><div>' . ($blockers
        ? '<span class="muted">Waiting on the branch team:</span> ' . e(implode('; ', array_map(fn($b) => $b['label'] . ' — ' . $b['detail'], $blockers)))
        : 'All tills are counted and reconciled.') . '</div></li>';
    // step 1: backup
    echo '<li class="' . ($pre ? 'done' : 'todo') . '"><b>1. Back up</b><div>' . ($pre ? 'Verified backup taken ' . e(fmt_dt($pre['at'])) . ' (' . e((string)$pre['file']) . ').' : 'A verified backup of the whole database must be taken before End of Day.') . '</div>';
    if (can('backup.manage')) {
        form_open(url('eodsys', ['a' => 'backup']), ['attrs' => 'style="grid-template-columns:auto;display:block;margin-top:.4rem" data-confirm="Take a verified backup now?"']);
        field('branch', '', ['type' => 'hidden', 'value' => $bid]);
        submit_btn($pre ? 'Back up again' : 'Back up now', $pre ? 'default' : 'primary'); form_close();
    }
    echo '</li>';
    // step 2: run
    $ready = !$blockers && $pre && Eod::adminMode();
    echo '<li class="' . ($ready ? 'todo ready' : 'locked') . '"><b>2. Run End of Day</b><div>' . ($ready ? 'Closes and locks ' . e(fmt_date($bd)) . ', records the day, takes the closing backup and opens the next working day.' : 'Available after the branch is ready and the backup is done.') . '</div>';
    if (can('eod.run') && Eod::adminMode()) {
        form_open(url('eodsys', ['a' => 'run']), ['attrs' => 'style="grid-template-columns:1fr;margin-top:.4rem" data-confirm="Run End of Day for ' . e($bn) . '? ' . e(fmt_date($bd)) . ' will be closed and locked."']);
        field('branch', '', ['type' => 'hidden', 'value' => $bid]);
        field('notes', 'Notes (optional)', ['type' => 'textarea', 'attrs' => $ready ? '' : 'disabled']);
        field('confirm', 'I confirm the branch has finished for the day and I am closing ' . fmt_date($bd) . '.', ['type' => 'checkbox', 'attrs' => $ready ? '' : 'disabled']);
        echo '<div class="form-actions"><button class="btn primary" ' . ($ready ? '' : 'disabled') . '>' . icon('check', 15) . ' Run End of Day</button></div>'; form_close();
    }
    echo '</li></ol></div>';
}

echo '<div class="card"><h2>End-of-Day history</h2>';
data_table(['branch' => 'Branch', 'business_date' => ['Business date', 'date'], 'status' => ['Status', 'badge'], 'closed_at' => ['Closed', 'dt'], 'closer' => 'Closed by', 'backup' => 'Closing backup'],
    rows("SELECT br.name AS branch, d.business_date, d.status, d.closed_at, u.full_name AS closer,
          (SELECT e.backup_status FROM eod_records e WHERE e.branch_id = d.branch_id AND e.business_date = d.business_date ORDER BY e.version DESC LIMIT 1) AS backup
          FROM business_days d JOIN branches br ON br.id = d.branch_id LEFT JOIN users u ON u.id = d.closed_by ORDER BY d.business_date DESC, br.name LIMIT 30"), ['class' => 'nofilter', 'empty' => 'No business days yet.']);
echo '</div>';
