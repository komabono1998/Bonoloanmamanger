<?php
$title = 'My approvals';
$items = Approvals::inbox();
page_head('My approvals', '', 'Everything waiting for your decision within your authority. Items above your limit go to the COO automatically.');
$have = Approvals::authority();
echo '<div class="kpis">' . kpi('Waiting for me', num(count($items)), '', count($items) ? 'warn' : 'ok') . kpi('My authority', e($have ?? 'none'), '', 'info') . '</div>';
data_table(['type' => 'What', 'ref' => 'Reference', 'detail' => 'Details', 'branch' => 'Branch', 'amount' => ['Amount', 'money'], 'age' => ['Since', 'dt'],
    'go' => ['', fn($r) => '<a class="btn sm primary" href="' . e(url($r['url'][0], $r['url'][1])) . '">Review</a>']], $items, ['empty' => 'Nothing is waiting for you.', 'class' => 'nofilter']);
