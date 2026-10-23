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
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>' . ($sub ? '<p class="sub">' . e($sub) . '</p>' : '') . '</div><div class="actions">' . $actions . '</div></div>';
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
    $id = insert('attachments', ['branch_id' => branch_id() ?: null, 'entity_type' => $entityType, 'entity_id' => $entityId, 'kind' => $kind, 'original_name' => basename($f['name']),
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
