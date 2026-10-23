<?php
/**
 * System date and End-of-Day for the technical administrator: dates and status only (no financial figures).
 * The administrator can take a verified backup before End-of-Day; closing the day stays with the branch's finance and
 * management users (separation of duties) and moves the system date on automatically.
 */
$title = 'End of Day & system date';
if (get('a') === 'backup') post_action(function () {
    require_perm('backup.manage');
    $bid = (int)post('branch');
    must(isset(all_branch_options(false)[$bid]), 'Choose a branch.');
    $bd = current_bd($bid);
    $b = Backup::create('Pre-EOD', $bd);
    must($b['ok'], 'The backup failed: ' . $b['message']);
    return 'Verified backup created before End-of-Day for ' . branch_name($bid) . ' (' . ($bd ? fmt_date($bd) : 'no open day') . '): ' . $b['file'];
});

page_head('End of Day & system date', '', 'The system date is the open business date of each branch. It moves to the next working day when that branch’s End-of-Day is closed.');
echo '<div class="notice info">End-of-Day is prepared by the branch Finance Officer and approved by the Branch Manager (or COO) — the System Administrator cannot close a business date. Here you can see where each branch stands and take a <b>verified backup before End-of-Day</b>.</div>';
echo '<div class="card"><h2>System date by branch</h2>';
$rows = [];
foreach (all_branch_options(false) as $bid => $bn) {
    $bd = current_bd($bid);
    $last = val("SELECT MAX(business_date) FROM business_days WHERE branch_id = ? AND status = 'Closed'", [$bid]);
    $rows[] = ['id' => $bid, 'name' => $bn, 'bd' => $bd, 'status' => $bd ? Eod::status($bd, $bid) : 'No day open', 'last' => $last,
        'next' => $bd ? '' : Eod::suggestNextDate($bid)];
}
data_table(['name' => 'Branch', 'bd' => ['System date', fn($r) => $r['bd'] ? '<b>' . e(fmt_date($r['bd'])) . '</b>' : '<span class="muted">none open</span>'], 'status' => ['End-of-Day status', 'badge'], 'last' => ['Last closed', 'date'],
    'act' => ['Before End-of-Day', fn($r) => can('backup.manage') ? '<form method="post" action="' . e(url('eodsys', ['a' => 'backup'])) . '" data-confirm="Take a verified backup now?">' . csrf_field() . '<input type="hidden" name="branch" value="' . (int)$r['id'] . '"><button class="btn sm primary">' . icon('database', 13) . ' Back up now</button></form>' : '']],
    $rows, ['class' => 'nofilter', 'empty' => 'No branches yet.']);
echo '<p class="muted">A backup covers the whole organisation database (all branches). The Branch Manager can also tick “take a backup before closing” when approving End-of-Day, or your policy can require it (Settings → Cash &amp; End of Day).</p></div>';
echo '<div class="card"><h2>Recent backups</h2>';
data_table(['at' => ['When', 'dt'], 'type' => 'Type', 'file' => 'File', 'status' => ['Result', 'badge']], rows('SELECT at, type, file, status FROM backup_history ORDER BY id DESC LIMIT 10'), ['class' => 'nofilter', 'empty' => 'No backups yet.']);
echo '</div>';
