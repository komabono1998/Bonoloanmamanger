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
if (get('a') === 'closetills') post_action(function () use ($branches) {
    require_perm('till.reconcile');
    $bid = (int)post('branch');
    must(isset($branches[$bid]), 'Choose a branch.');
    $bd = current_bd($bid);
    must($bd !== null, 'This branch has no open business day.');
    $counts = [];
    foreach ((array)($_POST['counted'] ?? []) as $sid => $v) $counts[(int)$sid] = [trim((string)$v), trim((string)($_POST['reason'][$sid] ?? ''))];
    $n = Cash::closeOpenTills($bid, $bd, $counts);
    return $n ? $n . ' till(s) counted and reconciled for ' . branch_name($bid) . '. You can now run End of Day.' : 'There were no open tills to close.';
});
if (get('a') === 'reset') post_action(function () use ($branches) {
    require_perm('eod.reset');
    $bid = (int)post('branch');
    must(isset($branches[$bid]), 'Choose a branch.');
    must(!empty($_POST['confirm']), 'Tick the confirmation to reset the End-of-Day position.');
    $r = Eod::resetPosition($bid, clean(post('reason')));
    return 'End-of-Day position reset for ' . branch_name($bid) . ': ' . fmt_date($r['reopened']) . ' is open again' . ($r['removed'] ? ' (the empty day ' . fmt_date($r['removed']) . ' was withdrawn)' : '') . '. Correct what went wrong, then run End of Day again.';
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
            if ($ph === 'check') {
                $al = Eod::alignmentBlock($bid, $date);
                must(!$al, $al ? 'All branches share one system date. ' . $al[0] . ' is still on ' . fmt_date($al[1]) . ' and must close first.' : '');
            }
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

// ---- institution-wide End of Day: every office on the same date closes together
$offices = [];   // offices with an open day
foreach ($branches as $ob => $on) { $od = current_bd((int)$ob); if ($od) $offices[(int)$ob] = ['name' => $on, 'date' => $od]; }
$minDate = $offices ? min(array_column($offices, 'date')) : null;
$part = []; $waiting = [];   // participants (on the earliest date) and offices already ahead
foreach ($offices as $ob => $o) { if ($o['date'] === $minDate || setting('eod_align_branches') === '0') $part[$ob] = $o; else $waiting[$ob] = $o; }
$problems = [];
foreach ($part as $ob => $o) foreach (Eod::blockers($o['date'], $ob) as $bl) $problems[] = $o['name'] . ': ' . $bl['label'] . ' (' . $bl['detail'] . ')';
$canRunAll = can('eod.run') && Eod::adminMode();
echo '<div class="card eod-all"><div class="eod-all-h"><div><h2>' . icon('check', 18) . ' End of Day &mdash; all offices</h2>';
if ($part) {
    echo '<p class="muted" style="margin:.2rem 0 0">System date <b>' . e(fmt_date($minDate)) . '</b> &middot; ' . count($part) . ' office' . (count($part) === 1 ? '' : 's') . ' close together: ' . e(implode(', ', array_column($part, 'name'))) . '.</p>';
    if ($waiting) echo '<p class="muted" style="margin:.2rem 0 0">Already on a later date, waiting for the rest to catch up: ' . e(implode(', ', array_map(fn($w) => $w['name'] . ' (' . fmt_date($w['date']) . ')', $waiting))) . '.</p>';
} else echo '<p class="muted" style="margin:.2rem 0 0">No office has an open business day. Start a business day below.</p>';
echo '</div>';
if ($part && $canRunAll) {
    $catchTo = $waiting ? max(array_column($waiting, 'date')) : '';
    echo '<button type="button" class="btn primary eod-btn" data-eod-all' . ($catchTo ? ' data-catchup="' . e($catchTo) . '" data-catchup-label="' . e(fmt_date($catchTo)) . '"' : '') . ($problems ? ' disabled title="Every office must be ready first"' : '') . '>' . icon('check', 18) . ' Run End of Day for all ' . count($part) . ' office' . (count($part) === 1 ? '' : 's') . '</button>';
} elseif ($part) echo '<p class="muted">You do not have permission to run End of Day.</p>';
echo '</div>';
if ($part && $canRunAll) echo '<details class="eod-note"><summary>Add a note (optional)</summary><textarea data-eod-notes-global rows="2" placeholder="Anything worth recording about today"></textarea></details>';
if ($part && $problems) {
    echo '<div class="eod-wait"><b>Not every office is ready yet</b><ul>';
    foreach ($problems as $pm) echo '<li>' . e($pm) . '</li>';
    echo '</ul><span class="muted">Close and count the tills below (or use "Close the open tills now"), then the button unlocks.</span></div>';
}
echo '<div class="eod-master" data-eod-master hidden><div class="eod-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-master-fill></span></div><div class="eod-pct" data-master-pct>0%</div><div class="eod-result" data-master-result hidden></div></div>';
echo '</div>';

foreach ($branches as $bid => $bn) {
    $bd = current_bd((int)$bid);
    $last = val("SELECT MAX(business_date) FROM business_days WHERE branch_id = ? AND status = 'Closed'", [$bid]);
    echo '<div class="card eod-card"><div class="card-h"><h2>' . icon('building', 18) . ' ' . e($bn) . '</h2><span class="sysdate-pill">' . icon('calendar', 14) . ' System date <b>' . ($bd ? e(fmt_date($bd)) : 'no day open') . '</b></span></div>';
    if (!$bd) {
        echo '<p class="muted">' . ($last ? 'Last closed business date: <b>' . e(fmt_date($last)) . '</b>.' : 'No business day has been opened yet.') . ' Start the next day to open the office for work.</p>';
        form_open(url('eodsys', ['a' => 'startday']), ['attrs' => 'style="grid-template-columns:1fr 1fr auto;align-items:end"']);
        field('branch', '', ['type' => 'hidden', 'value' => $bid]);
        field('date', 'Business date', ['type' => 'date', 'value' => Eod::suggestNextDate((int)$bid), 'required' => true]);
        field('allow_nonworking', 'Open even if not a working day', ['type' => 'checkbox']);
        echo '<div class="fld"><label>&nbsp;</label>'; submit_btn('Start business day'); echo '</div>'; form_close();
        echo '</div>';
        continue;
    }
    $blockers = Eod::blockers($bd, (int)$bid);
    $isPart = isset($part[(int)$bid]);
    echo '<div class="eod-go" data-eod' . ($isPart ? ' data-participant="1"' : '') . ' data-branch="' . (int)$bid . '" data-date="' . e($bd) . '" data-datelabel="' . e(fmt_date($bd)) . '" data-url="' . e(url('eodsys', ['a' => 'step'])) . '" data-csrf="' . e(csrf_token()) . '" data-branchname="' . e($bn) . '">';
    echo '<div class="eod-ready">';
    if (!$isPart) echo '<p class="muted"><b>Waiting.</b> This office is already on ' . e(fmt_date($bd)) . '; it moves on again once the others reach this date.</p>';
    elseif ($blockers) {
        echo '<div class="eod-wait"><b>Not ready to close yet</b><ul>';
        foreach ($blockers as $b) echo '<li>' . e($b['label']) . ' <span class="muted">&mdash; ' . e($b['detail']) . '</span></li>';
        echo '</ul><a class="btn sm" href="' . e(url('till')) . '">Open Cashier &amp; Till</a></div>';
        $open = rows("SELECT s.*, u.full_name AS cashier FROM cashier_sessions s JOIN users u ON u.id = s.cashier_id WHERE s.branch_id = ? AND s.business_date = ? AND s.status IN ('Open','Submitted','Investigation') ORDER BY s.id", [$bid, $bd]);
        if ($open && can('till.reconcile') && can('eod.run')) {
            echo '<div class="eod-tills"><b>Close the open tills now</b><p class="muted">As administrator you can count and reconcile every open till here. Enter the cash actually counted (it starts at the expected amount); a difference needs a reason.</p>';
            form_open(url('eodsys', ['a' => 'closetills']), ['attrs' => 'style="display:block" data-confirm="Count and reconcile these tills for ' . e($bn) . '?"']);
            echo '<input type="hidden" name="branch" value="' . (int)$bid . '"><table class="tbl"><thead><tr><th>Till</th><th>Cashier</th><th>Status</th><th class="r">Expected cash</th><th class="r">Counted cash</th><th>Reason if different</th></tr></thead><tbody>';
            foreach ($open as $s2) {
                $exp = Cash::expected((int)$s2['id']);
                echo '<tr><td>' . e($s2['session_no']) . '</td><td>' . e($s2['cashier']) . '</td><td>' . badge($s2['status']) . '</td><td class="r">' . num($exp) . '</td>'
                    . '<td class="r">' . ($s2['status'] === 'Submitted' ? '<span class="muted">' . num((int)$s2['counted_cash']) . '</span>' : '<input type="text" inputmode="numeric" class="money" name="counted[' . (int)$s2['id'] . ']" value="' . $exp . '">') . '</td>'
                    . '<td>' . ($s2['status'] === 'Submitted' ? '<span class="muted">already counted</span>' : '<input type="text" name="reason[' . (int)$s2['id'] . ']" placeholder="Only if the count differs">') . '</td></tr>';
            }
            echo '</tbody></table><div class="form-actions" style="margin-top:.6rem">'; submit_btn('Count and reconcile these tills'); echo '</div>'; form_close(); echo '</div>';
        }
    } else echo '<p class="muted">Ready: every till is counted and reconciled.</p>';
    echo '</div><div class="eod-run" hidden><div class="eod-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-eod-fill></span></div>'
        . '<div class="eod-pct" data-eod-pct>0%</div><ol class="eod-list">';
    foreach (Eod::PHASES as $k => $label) echo '<li data-phase="' . e($k) . '"><span class="eod-ic"></span><span class="eod-lb">' . e($label) . '</span><small data-msg></small></li>';
    echo '<li data-phase="done"><span class="eod-ic"></span><span class="eod-lb">Sending the completion notice</span><small data-msg></small></li>';
    echo '</ol><div class="eod-result" data-eod-result hidden></div></div></div></div>';
}
echo '<div class="eod-modal" data-eod-modal hidden role="dialog" aria-modal="true" aria-labelledby="eod-q"><div class="eod-dialog"><h2 id="eod-q">Run End of Day?</h2>'
    . '<p data-eod-question></p><p class="muted">Every office will be closed and locked for its business date, and the system date moves to the next working day for all of them together.</p>'
    . '<div class="eod-dialog-actions"><button type="button" class="btn" data-eod-no>No, not yet</button><button type="button" class="btn primary" data-eod-yes>Yes, run End of Day</button></div></div></div>';
echo '<script src="' . e(asset('eod_run.js')) . '"></script>';

if (can('eod.reset')) {
    echo '<div class="card eod-reset"><h2>' . icon('lock', 18) . ' Made a mistake? Reset the End-of-Day position</h2>'
        . '<p class="muted">Takes an office back one day: the last closed business date is reopened and the empty day that opened after it is withdrawn, so the system date goes back. Use it when End of Day closed on a mistake. It is refused if the later day already has transactions. For a damaged database, restore the closing backup under <a href="' . e(url('backup')) . '">Backup &amp; Recovery</a> instead.</p>';
    foreach ($branches as $rbid => $rbn) {
        $rl = val("SELECT MAX(business_date) FROM business_days WHERE branch_id = ? AND status = 'Closed'", [$rbid]);
        if (!$rl) { echo '<p class="muted"><b>' . e($rbn) . '</b>: no closed business date yet.</p>'; continue; }
        $ro = current_bd((int)$rbid);
        $act = $ro ? Eod::activityOn((int)$rbid, $ro) : [];
        echo '<form method="post" action="' . e(url('eodsys', ['a' => 'reset'])) . '" class="eod-reset-row" data-confirm="Reset ' . e($rbn) . ' back to ' . e(fmt_date($rl)) . '?">' . csrf_field() . '<input type="hidden" name="branch" value="' . (int)$rbid . '">'
            . '<div><b>' . e($rbn) . '</b><br><span class="muted">Last closed ' . e(fmt_date($rl)) . ($ro ? ' &middot; open day ' . e(fmt_date($ro)) . ($act ? ' <span class="warn">(has ' . e(implode(', ', $act)) . ': cannot be taken back)</span>' : ' (empty)') : ' &middot; no day open') . '</span></div>'
            . '<input type="text" name="reason" required placeholder="Why is the position being reset?"><label class="chk"><input type="checkbox" name="confirm" value="1"> I understand</label>'
            . '<button class="btn danger" type="submit"' . ($act ? ' disabled' : '') . '>Reset to ' . e(fmt_date($rl)) . '</button></form>';
    }
    echo '</div>';
    $resets = rows('SELECT r.*, b.name AS branch, u.full_name AS who FROM eod_resets r JOIN branches b ON b.id = r.branch_id LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 20');
    if ($resets) { echo '<div class="card"><h2>Reset history</h2>'; data_table(['at' => ['When', 'dt'], 'branch' => 'Branch', 'reopened_date' => ['Reopened date', 'date'], 'removed_date' => ['Empty day withdrawn', 'date'], 'who' => 'By', 'reason' => 'Reason'], $resets, ['class' => 'nofilter']); echo '</div>'; }
}
echo '<div class="card"><h2>End-of-Day history</h2>';
data_table(['branch' => 'Branch', 'business_date' => ['Business date', 'date'], 'status' => ['Status', 'badge'], 'closed_at' => ['Closed', 'dt'], 'closer' => 'Closed by', 'backup' => 'Closing backup'],
    rows("SELECT br.name AS branch, d.business_date, d.status, d.closed_at, u.full_name AS closer,
          (SELECT e.backup_status FROM eod_records e WHERE e.branch_id = d.branch_id AND e.business_date = d.business_date ORDER BY e.version DESC LIMIT 1) AS backup
          FROM business_days d JOIN branches br ON br.id = d.branch_id LEFT JOIN users u ON u.id = d.closed_by ORDER BY d.business_date DESC, br.name LIMIT 30"), ['class' => 'nofilter', 'empty' => 'No business days yet.']);
echo '</div>';
