<?php
/** Small server-side UI toolkit: forms, tables, KPI tiles, post handling and file uploads. */

/** Remember submitted values so a failed form can be re-shown with the user's input. */
function old(string $k, $default = '')
{
    return $_SESSION['old'][$k] ?? $default;
}

function clear_old(): void { unset($_SESSION['old']); }

/**
 * Run a POST action with CSRF protection, error handling and post/redirect/get.
 * The callback returns a success message, or [message, redirectUrl].
 */
function post_action(callable $fn, ?string $failTo = null): void
{
    if (!is_post()) return;
    $self = $_SERVER['REQUEST_URI'] ?? url('dashboard');
    try {
        csrf_check();
        $res = $fn();
        clear_old();
        [$msg, $to] = is_array($res) ? [$res[0], $res[1] ?? $self] : [$res, $self];
        if ($msg) flash('ok', (string)$msg);
        redirect($to);
    } catch (AppError $e) {
        $_SESSION['old'] = $_POST;
        flash('error', $e->getMessage());
        redirect($failTo ?? $self);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['old'] = $_POST;
        $m = $e->getMessage();
        if (stripos($m, 'UNIQUE') !== false) flash('error', 'This record conflicts with an existing one (a unique value is already in use).');
        elseif (preg_match('/([^\n:]*cannot be (deleted|altered|modified|truncated)[^\n"\']*)/', $m, $x)) flash('error', ucfirst(trim($x[1])));
        elseif (stripos($m, 'locked') !== false) flash('error', 'The database is busy. Please try again in a moment.');
        else flash('error', 'The transaction could not be completed and nothing was saved. If this continues, contact the administrator.');
        redirect($failTo ?? $self);
    } catch (Throwable $e) {
        error_log($e);
        $_SESSION['old'] = $_POST;
        flash('error', 'Something went wrong and nothing was saved. If this continues, contact the administrator.');
        redirect($failTo ?? $self);
    }
}

function page_head(string $title, string $actions = '', string $sub = ''): void
{
    $words = explode(' ', trim($title)); $last = count($words) > 1 ? array_pop($words) : null;
    $h1 = $last === null ? e($title) : e(implode(' ', $words)) . ' <span class="accent">' . e($last) . '</span>';
    echo '<div class="page-head"><div><h1>' . $h1 . '</h1>' . ($sub ? '<p class="sub">' . e($sub) . '</p>' : '') . '</div><div class="actions">' . $actions . '</div></div>';
}

function btn(string $label, string $href, string $cls = ''): string
{
    return '<a class="btn ' . $cls . '" href="' . e($href) . '">' . e($label) . '</a>';
}

function form_open(?string $action = null, array $o = []): void
{
    $m = $o['method'] ?? 'post';
    $enc = !empty($o['upload']) ? ' enctype="multipart/form-data"' : '';
    $attrs = $o['attrs'] ?? '';
    echo '<form method="' . $m . '" action="' . e($action ?? ($_SERVER['REQUEST_URI'] ?? '')) . '" class="' . e($o['class'] ?? 'form') . '"' . $enc . ' ' . $attrs . '>';
    if ($m === 'post') echo csrf_field();
}

function form_close(): void { echo '</form>'; }

/**
 * Render a form field. Options: type (text|money|number|date|select|textarea|checkbox|password|tel|hidden|file),
 * value, options (for select: value=>label), required, help, span (1-4), attrs, placeholder.
 */
function field(string $name, string $label, array $o = []): void
{
    $type = $o['type'] ?? 'text';
    $val = old($name, $o['value'] ?? '');
    $req = !empty($o['required']) ? ' required' : '';
    $attrs = $o['attrs'] ?? '';
    $ph = isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '';
    $id = 'f_' . preg_replace('/\W/', '_', $name);
    if ($type === 'hidden') { echo '<input type="hidden" name="' . e($name) . '" value="' . e($o['value'] ?? '') . '">'; return; }
    echo '<div class="fld span' . (int)($o['span'] ?? 1) . '">';
    if ($type === 'checkbox') {
        $checked = ($val !== '' && $val !== '0' && $val !== 0 && $val !== false && $val !== null) ? ' checked' : '';
        echo '<label class="chk"><input type="checkbox" id="' . $id . '" name="' . e($name) . '" value="1"' . $checked . ' ' . $attrs . '> ' . e($label) . '</label>';
    } else {
        echo '<label for="' . $id . '">' . e($label) . (!empty($o['required']) ? ' <b class="req">*</b>' : '') . '</label>';
        if ($type === 'select') {
            echo '<select id="' . $id . '" name="' . e($name) . '"' . $req . ' ' . $attrs . '>';
            if (!empty($o['blank'])) echo '<option value="">' . e($o['blank'] === true ? '— select —' : $o['blank']) . '</option>';
            foreach ($o['options'] ?? [] as $k => $v) echo '<option value="' . e($k) . '"' . ((string)$k === (string)$val ? ' selected' : '') . '>' . e($v) . '</option>';
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . (int)($o['rows'] ?? 3) . '"' . $req . $ph . ' ' . $attrs . '>' . e($val) . '</textarea>';
        } elseif ($type === 'money') {
            echo '<input type="text" inputmode="numeric" class="money" id="' . $id . '" name="' . e($name) . '" value="' . e($val === '' ? '' : (is_numeric($val) ? number_format((int)$val) : $val)) . '"' . $req . $ph . ' autocomplete="off" ' . $attrs . '>';
        } elseif ($type === 'file') {
            echo '<input type="file" id="' . $id . '" name="' . e($name) . '" ' . ($o['accept'] ?? '') . ' ' . $attrs . '>';
        } else {
            echo '<input type="' . e($type) . '" id="' . $id . '" name="' . e($name) . '" value="' . e($val) . '"' . $req . $ph . ' ' . $attrs . '>';
        }
    }
    if (!empty($o['help'])) echo '<small>' . e($o['help']) . '</small>';
    echo '</div>';
}

function submit_btn(string $label = 'Save', string $cls = 'primary', string $attrs = ''): void
{
    echo '<button type="submit" class="btn ' . $cls . '" ' . $attrs . '>' . e($label) . '</button>';
}

function kpi(string $label, $value, string $sub = '', string $tone = ''): string
{
    return '<div class="kpi ' . $tone . '"><div class="kl">' . e($label) . '</div><div class="kv">' . $value . '</div>' . ($sub ? '<div class="ks">' . e($sub) . '</div>' : '') . '</div>';
}

/**
 * Data table. $cols: key => label | [label, fmt, class]. fmt: text|money|num|date|dt|badge|html|callable(row).
 * $opts: link (callable row => url), empty (message), class, totals (cols to total), id.
 */
function data_table(array $cols, array $rows, array $opts = []): void
{
    $cls = 'tbl datatable ' . ($opts['class'] ?? '');
    echo '<div class="tbl-wrap"><table class="' . $cls . '"' . (!empty($opts['id']) ? ' id="' . e($opts['id']) . '"' : '') . '><thead><tr>';
    $norm = [];
    foreach ($cols as $k => $c) {
        $c = is_array($c) ? $c : [$c];
        $norm[$k] = ['label' => $c[0], 'fmt' => $c[1] ?? 'text', 'class' => $c[2] ?? ''];
        if (in_array($norm[$k]['fmt'], ['money', 'num'], true) && !str_contains($norm[$k]['class'], 'r')) $norm[$k]['class'] .= ' r';
        echo '<th class="' . e(trim($norm[$k]['class'])) . '">' . e($c[0]) . '</th>';
    }
    echo '</tr></thead><tbody>';
    $tot = [];
    if (!$rows) echo '<tr><td colspan="' . count($cols) . '" class="empty">' . e($opts['empty'] ?? 'Nothing to show.') . '</td></tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($norm as $k => $c) {
            $v = $r[$k] ?? '';
            $f = $c['fmt'];
            if (is_callable($f) && !is_string($f)) $out = $f($r);
            else switch ($f) {
                case 'money': $out = $v === '' || $v === null ? '' : num($v); if (!empty($opts['totals']) && in_array($k, $opts['totals'], true)) $tot[$k] = ($tot[$k] ?? 0) + (int)$v; break;
                case 'num': $out = $v === '' || $v === null ? '' : num($v); if (!empty($opts['totals']) && in_array($k, $opts['totals'], true)) $tot[$k] = ($tot[$k] ?? 0) + (int)$v; break;
                case 'date': $out = e(fmt_date($v)); break;
                case 'dt': $out = e(fmt_dt($v)); break;
                case 'badge': $out = $v === '' ? '' : badge((string)$v); break;
                case 'html': $out = (string)$v; break;
                default: $out = e($v);
            }
            if (!empty($opts['link']) && $k === array_key_first($norm) && !is_callable($f)) {
                $out = '<a href="' . e($opts['link']($r)) . '">' . $out . '</a>';
            }
            $sortv = in_array($f, ['money', 'num'], true) ? ' data-v="' . (int)$v . '"' : '';
            echo '<td class="' . e(trim($c['class'])) . '"' . $sortv . '>' . $out . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    if ($tot) {
        echo '<tfoot><tr>';
        $first = true;
        foreach ($norm as $k => $c) {
            echo '<td class="' . e(trim($c['class'])) . '">' . (isset($tot[$k]) ? '<b>' . num($tot[$k]) . '</b>' : ($first ? '<b>Total</b>' : '')) . '</td>';
            $first = false;
        }
        echo '</tr></tfoot>';
    }
    echo '</table></div>';
}

/** Key/value description list for detail views. */
function desc_list(array $pairs, int $cols = 3): void
{
    echo '<dl class="dl c' . $cols . '">';
    foreach ($pairs as $k => $v) {
        if ($v === null || $v === '') $v = '<span class="muted">—</span>'; // plain text is escaped by caller via e() or html
        echo '<div><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
    }
    echo '</dl>';
}

function tabs(array $tabs, string $current, string $param = 'tab', array $extra = []): void
{
    echo '<nav class="tabs">';
    foreach ($tabs as $k => $label) {
        $q = $_GET;
        $q[$param] = $k;
        echo '<a class="' . ($k === $current ? 'on' : '') . '" href="?' . e(http_build_query($q)) . '">' . e($label) . '</a>';
    }
    echo '</nav>';
}

function filter_bar(array $fields, array $values, string $page, array $hidden = []): void
{
    echo '<form method="get" action="' . e(base_url() . '/index.php') . '" class="filters"><input type="hidden" name="p" value="' . e($page) . '">';
    foreach ($hidden as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    foreach ($fields as $name => $f) {
        $v = $values[$name] ?? '';
        echo '<div class="fld"><label>' . e($f['label']) . '</label>';
        if (($f['type'] ?? 'text') === 'select') {
            echo '<select name="' . e($name) . '">';
            foreach (['' => $f['all'] ?? 'All'] + ($f['options'] ?? []) as $ok => $ov) echo '<option value="' . e($ok) . '"' . ((string)$ok === (string)$v ? ' selected' : '') . '>' . e($ov) . '</option>';
            echo '</select>';
        } else {
            echo '<input type="' . e($f['type'] ?? 'text') . '" name="' . e($name) . '" value="' . e($v) . '" placeholder="' . e($f['placeholder'] ?? '') . '">';
        }
        echo '</div>';
    }
    echo '<div class="fld"><label>&nbsp;</label><button class="btn" type="submit">Filter</button></div></form>';
}

// ------------------------------------------------------------------ uploads
function allowed_upload_types(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
}

/** Store an uploaded file under storage/uploads and register it. Returns attachment id, or null when no file sent. */
function save_upload(string $field, string $entityType, int $entityId, string $kind = 'document', string $note = ''): ?int
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    must($f['error'] === UPLOAD_ERR_OK, 'The file could not be uploaded (error ' . $f['error'] . ').');
    must($f['size'] <= 5 * 1024 * 1024, 'Files must be 5 MB or smaller.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $types = allowed_upload_types();
    must(isset($types[$ext]), 'Only JPG, PNG, GIF, WEBP and PDF files are allowed.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    must($mime === $types[$ext] || ($ext === 'jpg' && $mime === 'image/jpeg'), 'The file content does not match its extension.');
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    Storage::put($stored, $f['tmp_name'], $types[$ext]);
    $id = insert('attachments', ['branch_id' => $entityType === 'user' ? null : (branch_id() ?: null), 'entity_type' => $entityType, 'entity_id' => $entityId, 'kind' => $kind, 'original_name' => basename($f['name']),
        'stored_name' => $stored, 'mime' => $types[$ext], 'size' => $f['size'], 'uploaded_by' => uid(), 'uploaded_at' => now(), 'note' => $note ?: null]);
    audit('File uploaded', 'Documents', $entityType, $entityId, null, $f['name']);
    return $id;
}

function attachment_url(int $id): string { return url('file', ['id' => $id]); }

function photo_img(?int $attId, string $cls = 'avatar'): string
{
    if (!$attId) return '<div class="' . $cls . ' ph">no photo</div>';
    return '<img class="' . $cls . '" src="' . e(attachment_url($attId)) . '" alt="photo">';
}

function client_select_options(): array
{
    $o = [];
    foreach (rows("SELECT id, client_no, full_name, phone FROM clients WHERE status = 'Active' AND " . branch_scope('branch_id', [branch_id()]) . ' ORDER BY full_name') as $c) $o[$c['id']] = $c['full_name'] . ' — ' . $c['client_no'] . ' · ' . $c['phone'];
    return $o;
}

function user_options(string $perm): array
{
    $o = [];
    $br = branch_id();
    foreach (rows("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name") as $u) {
        if (in_array($perm, user_permissions((int)$u['id']), true) && in_array($br, accessible_branch_ids((int)$u['id']), true)) $o[$u['id']] = $u['full_name'] . ' (' . $u['role'] . ')';
    }
    return $o;
}

/** Inline SVG icon (feather-style, stroke = currentColor). */
function icon(string $name, int $size = 18): string
{
    static $p = [
        'home' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10',
        'users' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'user-plus' => 'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M8.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M20 8v6 M23 11h-6',
        'settings' => 'M4 21v-7 M4 10V3 M12 21v-9 M12 8V3 M20 21v-5 M20 12V3 M1 14h6 M9 8h6 M17 16h6',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'database' => 'M12 8c4.97 0 9-1.34 9-3s-4.03-3-9-3-9 1.34-9 3 4.03 3 9 3z M21 12c0 1.66-4 3-9 3s-9-1.34-9-3 M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5',
        'activity' => 'M22 12h-4l-3 9L9 3l-3 9H2',
        'file' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M16 13H8 M16 17H8 M10 9H8',
        'dollar' => 'M12 1v22 M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
        'percent' => 'M19 5L5 19 M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z M17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        'calendar' => 'M19 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z M16 2v4 M8 2v4 M3 10h18',
        'hash' => 'M4 9h16 M4 15h16 M10 3L8 21 M16 3l-2 18',
        'image' => 'M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z M8.5 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z M21 15l-5-5L5 21',
        'lock' => 'M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2z M7 11V7a5 5 0 0 1 10 0v4',
        'alert' => 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z M12 9v4 M12 17h.01',
        'check' => 'M20 6L9 17l-5-5',
        'plus' => 'M12 5v14 M5 12h14',
        'search' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z M21 21l-4.35-4.35',
        'bar' => 'M18 20V10 M12 20V4 M6 20v-6',
        'clipboard' => 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2 M15 2H9a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z',
        'card' => 'M21 4H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z M1 10h22',
        'clock' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z M12 6v6l4 2',
        'briefcase' => 'M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16',
        'key' => 'M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
        'mail' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z M22 6l-10 7L2 6',
        'layers' => 'M12 2L2 7l10 5 10-5-10-5z M2 17l10 5 10-5 M2 12l10 5 10-5',
        'building' => 'M3 21h18 M5 21V7l8-4v18 M19 21V11l-6-4 M9 9v.01 M9 12v.01 M9 15v.01 M9 18v.01',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
        'inbox' => 'M22 12h-6l-2 3h-4l-2-3H2 M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z',
        'trend' => 'M23 6l-9.5 9.5-5-5L1 18 M17 6h6v6',
        'eye' => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9 M13.7 21a2 2 0 0 1-3.4 0',
        'menu' => 'M3 6h18 M3 12h18 M3 18h18',
        'chevron' => 'M9 18l6-6-6-6',
        'down' => 'M6 9l6 6 6-6',
        'download' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4 M7 10l5 5 5-5 M12 15V3',
    ];
    $d = $p[$name] ?? $p['file'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Route key => icon name for the sidebar and quick links. */
function route_icon(string $key): string
{
    return ['dashboard' => 'home', 'inbox' => 'inbox', 'compliance' => 'trend', 'search' => 'search', 'clients' => 'users', 'guarantors' => 'user', 'loans' => 'clipboard', 'assessment' => 'search', 'approvals' => 'check',
        'disbursements' => 'dollar', 'securities' => 'shield', 'schedules' => 'calendar', 'payments' => 'card', 'receipts' => 'file', 'portfolio' => 'briefcase', 'arrears' => 'alert', 'till' => 'clock',
        'cashbook' => 'layers', 'eod' => 'calendar', 'eodsys' => 'calendar', 'reports' => 'bar', 'reportaccess' => 'key', 'importexport' => 'download', 'audit' => 'eye', 'users' => 'users', 'branches' => 'building', 'settings' => 'settings',
        'backup' => 'database', 'security' => 'lock', 'system' => 'activity', 'account' => 'user', 'mfa' => 'key'][$key] ?? 'file';
}

/** Row of large quick-link tiles: [[page, params, label, icon, permission|null], ...]. */
function quick_tiles(array $items): string
{
    $o = '<div class="tiles">';
    foreach ($items as [$pg, $pa, $label, $ic, $perm]) {
        if ($perm && !can_any($perm)) continue;
        $o .= '<a class="tile" href="' . e(url($pg, $pa)) . '">' . icon($ic, 24) . '<span>' . e($label) . '</span></a>';
    }
    return $o . '</div>';
}

/** Soft rounded quick-action buttons for the dashboard: [[page, params, label, icon, permission|null], ...]. */
function quick_actions(array $items): string
{
    $o = '<div class="qa">';
    foreach ($items as [$pg, $pa, $label, $ic, $perm]) {
        if ($perm && !can_any($perm)) continue;
        $o .= '<a href="' . e(url($pg, $pa)) . '">' . icon($ic, 28) . '<span>' . e($label) . '</span></a>';
    }
    return $o . '</div>';
}

/** Latest profile photo attachment per staff user: [user_id => attachment_id]. */
function user_photo_map(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (rows("SELECT entity_id, MAX(id) AS id FROM attachments WHERE entity_type = 'user' AND kind = 'photo' GROUP BY entity_id") as $r) $m[(int)$r['entity_id']] = (int)$r['id'];
    }
    return $m;
}

/** Round profile picture, or the person's initial when there is no photo. */
function pfp(?int $attId, string $name, int $size = 36): string
{
    $st = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .42) . 'px';
    if ($attId) return '<img class="pfp" style="' . $st . '" src="' . e(attachment_url($attId)) . '" alt="">';
    return '<span class="pfp ph" style="' . $st . '">' . e(strtoupper(mb_substr(trim($name), 0, 1)) ?: '?') . '</span>';
}

/**
 * Photograph input with "Choose file" and "Take photo" (device camera). The browser script (app.js) fills the file input with the
 * captured picture, so the server only ever handles an ordinary upload named $name.
 */
function photo_field(string $name = 'photo', string $label = 'Photograph', array $o = []): void
{
    $span = (int)($o['span'] ?? 2);
    echo '<div class="fld span' . $span . ' photo-field" data-photo><label>' . e($label) . '</label><div class="photo-row">';
    echo !empty($o['current']) ? '<img class="photo-prev" src="' . e(attachment_url((int)$o['current'])) . '" alt="">' : '<div class="photo-prev ph">' . icon('user', 34) . '</div>';
    echo '<div class="photo-ctl"><div class="photo-btns"><label class="btn sm">' . icon('image', 14) . ' Choose file<input type="file" name="' . e($name) . '" accept="image/*" hidden data-photo-file></label>'
        . '<button type="button" class="btn sm" data-photo-cam>' . icon('eye', 14) . ' Take photo</button></div>'
        . '<small class="muted photo-msg">JPG, PNG or WEBP, up to 5 MB. Use <b>Take photo</b> to capture with the camera.</small>'
        . '<div class="cam" hidden><video playsinline muted></video><div class="photo-btns"><button type="button" class="btn primary sm" data-photo-snap>Capture</button><button type="button" class="btn sm" data-photo-cancel>Cancel</button></div></div>'
        . '</div></div></div>';
}
