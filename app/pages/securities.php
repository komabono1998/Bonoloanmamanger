<?php
$title = 'Securities';
$a = get('a');
$statuses = ['Proposed', 'Under Verification', 'Accepted', 'Pledged', 'Held', 'Released', 'Under Recovery', 'Recovered', 'Sold', 'Disputed'];

if ($a === 'status') {
    post_action(function () use ($statuses) {
        $sid = (int)post('security_id');
        $s = row('SELECT s.*, l.status AS loan_status, l.loan_no FROM securities s JOIN loans l ON l.id = s.loan_id WHERE s.id = ?', [$sid]);
        must((bool)$s, 'Security not found.');
        assert_branch((int)val('SELECT branch_id FROM loans WHERE id = ?', [$s['loan_id']]));
        $new = post('new_status');
        must(in_array($new, $statuses, true), 'Choose a status.');
        must($new !== $s['status'], 'The security already has that status.');
        $note = clean(post('note'));
        must($note !== '', 'A reason / authority note is required for every status change.');
        if ($new === 'Released') {
            require_perm('security.release');
            must(in_array($s['loan_status'], ['Closed', 'Cancelled', 'Rejected'], true) || can('loan.close'), 'Security can only be released after the loan is closed (or with management authorization).');
            must(in_array($s['loan_status'], ['Closed', 'Cancelled', 'Rejected'], true) || clean(post('authorization')) !== '', 'Enter the release authorization reference for a loan that is still open.');
        } else {
            must(can('security.manage') || can('security.release'), 'You do not have permission to perform this action.');
            if (in_array($new, ['Under Recovery', 'Recovered', 'Sold'], true)) must(can('loan.close'), 'Enforcement actions need management authorization and must follow the signed agreement and applicable law.');
        }
        tx(function () use ($sid, $s, $new, $note) {
            $upd = ['status' => $new];
            if ($new === 'Released') $upd += ['date_released' => reporting_date(), 'released_by' => uid(), 'release_authorization' => clean(post('authorization')) ?: 'Loan ' . $s['loan_status']];
            update('securities', $upd, 'id = :id', ['id' => $sid]);
            insert('security_history', ['security_id' => $sid, 'old_status' => $s['status'], 'new_status' => $new, 'changed_by' => uid(), 'changed_at' => now(), 'note' => $note]);
            audit('Security status changed', 'Securities', 'security', $sid, $s['status'], $new, $note, clean(post('authorization')));
        });
        return 'Security status updated.';
    });
}

page_head('Security & collateral register', btn('Print register', url('print', ['doc' => 'securities']), ''), 'Assets pledged by borrowers and guarantors. Register new security from the loan file.');
$q = get('q'); $st = get('status'); $lid = (int)get('loan_id');
$w = [branch_scope('l.branch_id')]; $p = [];
if ($q !== '') { $w[] = '(s.security_no LIKE ? OR s.description LIKE ? OR s.serial_no LIKE ? OR l.loan_no LIKE ? OR c.full_name LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($st !== '') { $w[] = 's.status = ?'; $p[] = $st; }
if ($lid) { $w[] = 's.loan_id = ?'; $p[] = $lid; }
$list = rows("SELECT s.*, l.loan_no, l.status AS loan_status, c.full_name, g.surname AS gname FROM securities s JOIN loans l ON l.id = s.loan_id JOIN clients c ON c.id = s.client_id
              LEFT JOIN guarantors g ON g.id = s.guarantor_id WHERE " . implode(' AND ', $w) . ' ORDER BY s.id DESC LIMIT 500', $p);
filter_bar(['q' => ['label' => 'Search', 'placeholder' => 'Security, description, serial, loan, client'], 'status' => ['label' => 'Status', 'type' => 'select', 'options' => array_combine($statuses, $statuses)]], ['q' => $q, 'status' => $st], 'securities', $lid ? ['loan_id' => $lid] : []);
echo '<div class="kpis">' . kpi('Items listed', num(count($list))) . kpi('Estimated value held', ugx(array_sum(array_map(fn($r) => in_array($r['status'], ['Pledged', 'Held', 'Accepted'], true) ? $r['est_value'] : 0, $list)))) . '</div>';
data_table(['security_no' => 'Security ID', 'loan_no' => 'Loan', 'full_name' => 'Client', 'type' => 'Type', 'description' => 'Description', 'serial_no' => 'Serial / Reg.',
    'owner' => ['Owner', fn($r) => e($r['owner_type'] . ($r['gname'] ? ' · ' . $r['gname'] : ''))], 'est_value' => ['Est. value', 'money'], 'storage_location' => 'Stored at', 'status' => ['Status', 'badge'],
    'act' => ['Update status', function ($r) use ($statuses) {
        if (!can('security.manage') && !can('security.release')) return '';
        $o = '';
        foreach ($statuses as $s) if ($s !== $r['status']) $o .= '<option>' . e($s) . '</option>';
        return '<form method="post" action="' . e(url('securities', ['a' => 'status'])) . '" data-confirm="Change the security status? This is audited." style="display:flex;gap:.25rem;flex-wrap:wrap">' . csrf_field()
            . '<input type="hidden" name="security_id" value="' . (int)$r['id'] . '"><select name="new_status" style="width:auto">' . $o . '</select><input type="text" name="note" placeholder="reason" required style="width:110px"><input type="text" name="authorization" placeholder="auth. ref (release)" style="width:110px"><button class="btn sm">Save</button></form>';
    }]], $list, ['link' => fn($r) => url('loan', ['id' => $r['loan_id'], 'tab' => 'securities']), 'empty' => 'No securities registered.']);
