<?php
$title = 'Import / export';
$a = get('a');
$tab = get('tab', 'import');

const IMPORT_CLIENT_COLS = ['full_name', 'gender', 'dob', 'nin', 'phone', 'phone2', 'address', 'village', 'parish', 'subcounty', 'district', 'occupation', 'business_name', 'monthly_income', 'monthly_expenses', 'notes'];
const IMPORT_LOAN_COLS = ['client_no', 'principal', 'interest_rate_pct', 'disbursement_date', 'due_date', 'amount_paid', 'purpose'];

if ($a === 'template') {
    $kind = get('kind') === 'loans' ? 'loans' : 'clients';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_' . $kind . '_template.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, $kind === 'clients' ? IMPORT_CLIENT_COLS : IMPORT_LOAN_COLS);
    fputcsv($o, $kind === 'clients' ? ['Nakato Sarah', 'Female', '1990-05-14', 'CF90012345ABCD', '0772123456', '', 'Plot 4 Main St', 'Kisenyi', 'Central', 'Kampala', 'Kampala', 'Trader', 'Sarah Stores', '1500000', '600000', 'Imported'] : ['CL-000001', '1000000', '20', '2026-09-20', '2026-10-20', '0', 'Opening balance']);
    exit;
}

/** Parse an uploaded CSV into [header, rows[]] (UTF-8, comma separated). */
function read_csv_upload(string $field): array
{
    $f = $_FILES[$field] ?? null;
    must($f && $f['error'] === UPLOAD_ERR_OK, 'Choose a CSV file to import.');
    must($f['size'] <= 2 * 1024 * 1024, 'The file is too large (2 MB maximum).');
    must(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'csv', 'Only .csv files can be imported.');
    $h = fopen($f['tmp_name'], 'r');
    $head = fgetcsv($h);
    must((bool)$head, 'The file is empty.');
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $head = array_map(fn($x) => strtolower(trim($x)), $head);
    $rows = [];
    while (($r = fgetcsv($h)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        if (count($rows) > 2000) throw new AppError('Import up to 2,000 rows at a time.');
    }
    return [$head, $rows, $f['name']];
}

if ($a === 'preview') post_action(function () {
    require_perm('import.run');
    $kind = post('kind') === 'loans' ? 'loans' : 'clients';
    assert_active_branch(branch_id());
    [$head, $rows, $fname] = read_csv_upload('csv');
    $need = $kind === 'clients' ? ['full_name', 'phone'] : ['client_no', 'principal', 'disbursement_date', 'due_date'];
    foreach ($need as $c) must(in_array($c, $head, true), "The file must have a '$c' column. Download the template for the layout.");
    $res = []; $seenNin = []; $seenPhone = [];
    foreach ($rows as $i => $r) {
        $err = [];
        if ($kind === 'clients') {
            $phone = preg_replace('/[^\d+]/', '', $r['phone'] ?? ''); $nin = strtoupper(str_replace(' ', '', $r['nin'] ?? ''));
            if (trim($r['full_name'] ?? '') === '') $err[] = 'name missing';
            if (strlen($phone) < 9) $err[] = 'invalid phone';
            if (($r['dob'] ?? '') !== '' && !is_date($r['dob'])) $err[] = 'dob not YYYY-MM-DD';
            if ($nin !== '') {
                if (val('SELECT COUNT(*) FROM clients WHERE nin = ?', [$nin])) $err[] = 'duplicate NIN (already registered)';
                if (isset($seenNin[$nin])) $err[] = 'duplicate NIN in file (row ' . $seenNin[$nin] . ')';
                $seenNin[$nin] = $i + 2;
            }
            $warn = [];
            if (val('SELECT COUNT(*) FROM clients WHERE phone = ? OR phone2 = ?', [$phone, $phone])) $warn[] = 'phone already registered';
            if (isset($seenPhone[$phone])) $warn[] = 'phone repeated in file';
            $seenPhone[$phone] = true;
            foreach (['monthly_income', 'monthly_expenses'] as $m) if (($r[$m] ?? '') !== '' && !ctype_digit(str_replace(',', '', $r[$m]))) $err[] = "$m not numeric";
        } else {
            $cl = row('SELECT id, status FROM clients WHERE client_no = ? AND branch_id = ?', [trim($r['client_no'] ?? ''), branch_id()]);
            if (!$cl) $err[] = 'client ID does not exist in this branch'; elseif ($cl['status'] !== 'Active') $err[] = 'client not active';
            $pr = (int)str_replace(',', '', $r['principal'] ?? '0');
            if ($pr <= 0) $err[] = 'principal invalid';
            if (!is_date($r['disbursement_date'] ?? '') || !is_date($r['due_date'] ?? '')) $err[] = 'dates must be YYYY-MM-DD';
            elseif ($r['due_date'] <= $r['disbursement_date']) $err[] = 'due date must be after disbursement';
            $paid = (int)str_replace(',', '', $r['amount_paid'] ?? '0');
            $rate = ($r['interest_rate_pct'] ?? '') !== '' ? pct_to_bp($r['interest_rate_pct']) : pct_to_bp(setting('interest_rate_pct'));
            if ($pr > 0 && $paid > $pr + Calc::pct($pr, $rate)) $err[] = 'amount paid exceeds principal + interest';
            $warn = [];
            if ($cl && val("SELECT COUNT(*) FROM loans WHERE client_id = ? AND source = 'import' AND approved_principal = ? AND disbursement_date = ?", [$cl['id'], $pr, $r['disbursement_date'] ?? ''])) $err[] = 'looks like a duplicate of an imported loan';
        }
        $res[] = ['n' => $i + 2, 'data' => $r, 'errors' => $err, 'warnings' => $warn ?? []];
    }
    $_SESSION['import'] = ['kind' => $kind, 'rows' => $res, 'file' => $fname, 'token' => bin2hex(random_bytes(8))];
    return ['Preview ready — nothing has been saved yet.', url('importexport', ['tab' => 'import', 'preview' => 1])];
});

if ($a === 'commit') post_action(function () {
    require_perm('import.run');
    $imp = $_SESSION['import'] ?? null;
    must($imp && hash_equals($imp['token'], post('token')), 'The import preview has expired. Upload the file again.');
    assert_active_branch(branch_id());
    must(current_bd() !== null || $imp['kind'] === 'clients', 'A business day must be open to import loans.');
    $ok = 0; $rej = 0;
    tx(function () use ($imp, &$ok, &$rej) {
        foreach ($imp['rows'] as $r) {
            if ($r['errors']) { $rej++; continue; }
            $d = $r['data'];
            if ($imp['kind'] === 'clients') {
                insert('clients', ['branch_id' => branch_id(), 'client_no' => make_number('client_prefix', 'client', false), 'full_name' => clean($d['full_name']), 'gender' => $d['gender'] ?: null, 'dob' => ($d['dob'] ?? '') ?: null,
                    'nin' => strtoupper(str_replace(' ', '', $d['nin'] ?? '')) ?: null, 'phone' => preg_replace('/[^\d+]/', '', $d['phone']), 'phone2' => preg_replace('/[^\d+]/', '', $d['phone2'] ?? '') ?: null,
                    'address' => ($d['address'] ?? '') ?: null, 'village' => ($d['village'] ?? '') ?: null, 'parish' => ($d['parish'] ?? '') ?: null, 'subcounty' => ($d['subcounty'] ?? '') ?: null, 'district' => ($d['district'] ?? '') ?: null,
                    'occupation' => ($d['occupation'] ?? '') ?: null, 'business_name' => ($d['business_name'] ?? '') ?: null,
                    'monthly_income' => (int)str_replace(',', '', $d['monthly_income'] ?? '0'), 'monthly_expenses' => (int)str_replace(',', '', $d['monthly_expenses'] ?? '0'),
                    'notes' => trim(($d['notes'] ?? '') . ' [imported]'), 'status' => 'Active', 'registered_by' => uid(), 'registered_at' => now()]);
            } else {
                $bd = posting_date();
                $cl = row('SELECT * FROM clients WHERE client_no = ?', [trim($d['client_no'])]);
                $pr = (int)str_replace(',', '', $d['principal']);
                $rate = ($d['interest_rate_pct'] ?? '') !== '' ? pct_to_bp($d['interest_rate_pct']) : pct_to_bp(setting('interest_rate_pct'));
                $t = Calc::terms($pr, $rate, 1, 0, 0, 0, 0, false);
                $lid = insert('loans', ['branch_id' => (int)$cl['branch_id'], 'loan_no' => make_number('loan_prefix', 'loan', true), 'client_id' => $cl['id'], 'officer_id' => uid(), 'application_date' => $d['disbursement_date'],
                    'purpose' => ($d['purpose'] ?? '') ?: 'Imported historical loan', 'amount_requested' => $pr, 'period_months' => 1, 'frequency' => 'monthly', 'status' => 'Active', 'source' => 'import',
                    'approved_principal' => $pr, 'rate_bp' => $rate, 'interest_method' => 'flat', 'interest_amount' => $t['interest'], 'processing_fee_bp' => 0, 'processing_fee' => 0, 'application_fee' => 0, 'inspection_fee' => 0,
                    'other_fees' => 0, 'fees_deducted' => 1, 'total_fees' => 0, 'net_disbursement' => $pr, 'total_contractual' => $pr + $t['interest'],
                    'penalty_terms' => json_encode(['enabled' => false, 'rate_bp' => 0, 'basis' => 'outstanding_principal', 'compound' => false, 'cap_bp' => 0, 'grace_days' => 0]),
                    'approved_by' => uid(), 'approved_at' => now(), 'approval_date' => $d['disbursement_date'], 'acceptance_status' => 'Accepted', 'acceptance_date' => $d['disbursement_date'],
                    'disbursement_date' => $d['disbursement_date'], 'due_date' => $d['due_date'], 'disbursed_at' => now(), 'created_at' => now()]);
                insert('loan_status_history', ['loan_id' => $lid, 'from_status' => null, 'to_status' => 'Active', 'user_id' => uid(), 'at' => now(), 'note' => 'Imported historical loan']);
                foreach ([['disbursement', 'principal', $pr, 'Opening principal (imported)'], ['interest', 'interest', $t['interest'], 'Contractual interest (imported)']] as [$ty, $co, $am, $ds]) {
                    insert('loan_ledger', ['loan_id' => $lid, 'business_date' => $bd, 'entry_date' => $d['disbursement_date'], 'type' => $ty, 'component' => $co, 'amount' => $am, 'ref_type' => 'import', 'description' => $ds, 'user_id' => uid(), 'created_at' => now()]);
                }
                insert('repayment_schedules', ['loan_id' => $lid, 'version' => 1, 'installment_no' => 1, 'due_date' => $d['due_date'], 'principal_due' => $pr, 'interest_due' => $t['interest'], 'total_due' => $pr + $t['interest'], 'status' => 'Current', 'created_at' => now()]);
                $paid = (int)str_replace(',', '', $d['amount_paid'] ?? '0');
                if ($paid > 0) {
                    $al = Calc::allocate($paid, ['interest' => $t['interest'], 'principal' => $pr], Payments::allocationOrder());
                    $pid = insert('payments', ['branch_id' => (int)$cl['branch_id'], 'loan_id' => $lid, 'client_id' => $cl['id'], 'payment_date' => $bd, 'business_date' => $bd, 'amount' => $paid, 'method' => 'Imported', 'reference' => 'OPENING-' . $lid, 'cashier_id' => uid(),
                        'remarks' => 'Opening balance: amount already paid before import', 'status' => 'Posted', 'txn_token' => 'import-' . $lid, 'prev_balance' => $pr + $t['interest'], 'is_opening' => 1, 'created_at' => now()]);
                    foreach ($al['allocations'] as $c => $amt) {
                        insert('payment_allocations', ['payment_id' => $pid, 'loan_id' => $lid, 'component' => $c, 'amount' => $amt]);
                        Loans::ledger($lid, 'payment', $c, -$amt, 'payment', $pid, 'Opening payment (imported)', $bd);
                    }
                }
                audit('Historical loan imported', 'Import', 'loan', $lid, null, $d['client_no'] . ' ' . $pr);
                Loans::autoClose($lid, 'import');
            }
            $ok++;
        }
        insert('import_history', ['at' => now(), 'user_id' => uid(), 'kind' => $imp['kind'], 'file' => $imp['file'], 'rows_total' => count($imp['rows']), 'rows_imported' => $ok, 'rows_rejected' => $rej, 'message' => null]);
        audit('Data imported', 'Import', 'import', $imp['kind'], null, "$ok imported, $rej rejected from " . $imp['file']);
    });
    unset($_SESSION['import']);
    if ($imp['kind'] === 'loans') Loans::refreshStatuses();
    return ["Imported $ok " . $imp['kind'] . ($rej ? "; $rej row(s) were rejected and skipped." : '.'), url('importexport', ['tab' => 'history'])];
});

page_head('Import / export', '', 'Controlled import with preview and validation; exports are logged and restricted.');
tabs(['import' => 'Import', 'export' => 'Export', 'history' => 'History'], $tab);

if ($tab === 'import') {
    $imp = $_SESSION['import'] ?? null;
    if ($imp && get('preview')) {
        $good = count(array_filter($imp['rows'], fn($r) => !$r['errors']));
        echo '<div class="card"><h2>Preview — ' . e($imp['kind']) . ' from ' . e($imp['file']) . '</h2><div class="kpis">' . kpi('Rows read', num(count($imp['rows']))) . kpi('Ready to import', num($good), '', 'ok') . kpi('Rejected', num(count($imp['rows']) - $good), '', count($imp['rows']) - $good ? 'bad' : '') . '</div>';
        $show = array_map(fn($r) => ['n' => $r['n'], 'name' => $r['data']['full_name'] ?? $r['data']['client_no'] ?? '', 'info' => $imp['kind'] === 'clients' ? ($r['data']['phone'] ?? '') . ' · ' . ($r['data']['nin'] ?? '') : ($r['data']['principal'] ?? '') . ' · ' . ($r['data']['disbursement_date'] ?? '') . ' → ' . ($r['data']['due_date'] ?? ''),
            'result' => $r['errors'] ? '<span class="ico-bad">✖ ' . e(implode('; ', $r['errors'])) . '</span>' : ($r['warnings'] ? '<span class="ico-warn">! ' . e(implode('; ', $r['warnings'])) . '</span>' : '<span class="ico-ok">✔ OK</span>')], $imp['rows']);
        data_table(['n' => ['Row', 'num'], 'name' => 'Name / client', 'info' => 'Details', 'result' => ['Validation', 'html']], $show);
        echo '<div style="display:flex;gap:.6rem;margin-top:1rem">';
        if ($good) {
            echo '<form method="post" action="' . e(url('importexport', ['a' => 'commit'])) . '" data-confirm="Import ' . $good . ' valid row(s)? Imported records are flagged as imported.">' . csrf_field() . '<input type="hidden" name="token" value="' . e($imp['token']) . '"><button class="btn primary">Import ' . $good . ' valid row(s)</button></form>';
        }
        echo btn('Start over', url('importexport')) . '</div></div>';
    } else {
        if (!can('import.run')) echo '<div class="notice">You can export data but you have not been given the right to import it.</div>';
        echo '<div class="grid g2">';
        foreach ((can('import.run') ? ['clients' => 'Client register', 'loans' => 'Historical loans & opening balances'] : []) as $k => $label) {
            echo '<div class="card"><h2>' . e($label) . '</h2><p class="muted">' . ($k === 'clients' ? 'Columns: ' . implode(', ', IMPORT_CLIENT_COLS) . '. Duplicate NINs are rejected; shared phones are flagged.' : 'One-month flat-interest loans already running. Columns: ' . implode(', ', IMPORT_LOAN_COLS) . '. Recorded as imported (not new system transactions); paid amounts become an opening payment with no cash movement. Requires an open business day.') . '</p>';
            form_open(url('importexport', ['a' => 'preview']), ['upload' => true, 'attrs' => 'style="grid-template-columns:1fr"']);
            field('kind', '', ['type' => 'hidden', 'value' => $k]); field('csv', 'CSV file (UTF-8)', ['type' => 'file', 'accept' => 'accept=".csv"']);
            echo '<div class="form-actions">'; submit_btn('Upload & preview'); echo btn('Download template', url('importexport', ['a' => 'template', 'kind' => $k])); echo '</div>'; form_close();
            echo '</div>';
        }
        echo '</div>';
    }
}

if ($tab === 'export') {
    if (!can('export.run')) echo '<div class="notice">Sensitive exports are restricted to authorized roles.</div>';
    else {
        $ex = ['clients' => 'Client records', 'loans' => 'Loan register', 'payments' => 'Payment records', 'schedules' => 'Repayment schedules', 'receipts' => 'Receipts', 'cashbook' => 'Cashbook records', 'eod' => 'EOD records'];
        echo '<div class="card"><h2>Data exports</h2><p class="muted">CSV opens directly in Excel. Every export is logged. For formatted financial reports use Reports → CSV / Excel / Print.</p>';
        data_table(['label' => 'Dataset', 'go' => ['', fn($r) => '<a class="btn sm" href="' . e(url('export', ['kind' => $r['k'], 'format' => 'csv'])) . '">CSV</a> <a class="btn sm" href="' . e(url('export', ['kind' => $r['k'], 'format' => 'xls'])) . '">Excel</a>']],
            array_map(fn($k, $l) => ['k' => $k, 'label' => $l], array_keys($ex), $ex), ['class' => 'nofilter']);
        echo '</div>';
    }
}

if ($tab === 'history') {
    echo '<div class="grid g2"><div class="card"><h2>Imports</h2>';
    data_table(['at' => ['When', 'dt'], 'kind' => 'Kind', 'file' => 'File', 'rows_total' => ['Rows', 'num'], 'rows_imported' => ['Imported', 'num'], 'rows_rejected' => ['Rejected', 'num']], rows('SELECT * FROM import_history ORDER BY id DESC LIMIT 100'), ['class' => 'nofilter', 'empty' => 'No imports yet.']);
    echo '</div><div class="card"><h2>Exports</h2>';
    data_table(['at' => ['When', 'dt'], 'who' => 'By', 'kind' => 'Dataset', 'format' => 'Format', 'rows_total' => ['Rows', 'num']], rows('SELECT h.*, u.full_name who FROM export_history h LEFT JOIN users u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 100'), ['class' => 'nofilter', 'empty' => 'No exports yet.']);
    echo '</div></div>';
}
