<?php
/** Tiny browser for the HTTP smoke tests: cookies, forms (CSRF and hidden fields are carried automatically), flash messages. */
final class Web
{
    public string $jar; public string $base; public string $err = ''; public string $lastUrl = ''; public string $body = ''; public int $code = 0;

    public function __construct(string $base) { $this->base = rtrim($base, '/'); $this->jar = tempnam(sys_get_temp_dir(), 'web'); }

    public function url(string $page, array $q = []): string { return $this->base . '/index.php?' . http_build_query(['p' => $page] + $q); }

    public function get(string $url): string { return $this->req($url, null); }

    public function req(string $url, ?array $post): string
    {
        $this->err = '';
        $ch = curl_init(str_starts_with($url, 'http') ? $url : $this->base . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 90]);
        if ($post !== null) {
            $hasFile = false; foreach ($post as $v) if ($v instanceof CURLFile) $hasFile = true;
            curl_setopt($ch, CURLOPT_POST, true);
            if ($hasFile) { $flat = []; foreach ($post as $k => $v) { if (is_array($v)) foreach ($v as $i => $one) $flat[$k . '[' . $i . ']'] = $one; else $flat[$k] = $v; } curl_setopt($ch, CURLOPT_POSTFIELDS, $flat); }
            else curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encode($post));
        }
        $this->body = (string)curl_exec($ch); $this->code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $this->lastUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $this->body;
    }

    private function encode(array $post): string
    {
        $parts = [];
        foreach ($post as $k => $v) foreach ((array)$v as $one) $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$one);
        return implode('&', $parts);
    }

    /** Value of the <option> whose text contains $label (for pick-lists such as the authorizing manager). */
    public function optionValue(string $label): ?string
    {
        return preg_match('~<option value="([^"]+)"[^>]*>[^<]*' . preg_quote($label, '~') . '~', $this->body, $m) ? $m[1] : null;
    }

    public function title(): string { return preg_match('~<title>([^<]*)</title>~', $this->body, $m) ? html_entity_decode($m[1]) : ''; }

    /** The flash message(s) shown on the current page: [class => text]. */
    public function flash(): array
    {
        $o = [];
        if (preg_match_all('~<div class="flash (\w+)">([^<]*)</div>~', $this->body, $m, PREG_SET_ORDER)) foreach ($m as $x) $o[] = [$x[1], html_entity_decode(trim($x[2]))];
        return $o;
    }

    public function flashText(): string { return implode(' | ', array_map(fn($f) => $f[0] . ': ' . $f[1], $this->flash())) . ($this->err !== '' ? ' [' . $this->err . ']' : ''); }

    /** All forms on the current page: [['action' => , 'fields' => [name => value|[values]], 'names' => [...]]] */
    public function forms(): array
    {
        $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8"?>' . $this->body);
        $out = [];
        foreach ($dom->getElementsByTagName('form') as $f) {
            $fields = []; $names = [];
            $add = function (string $n, string $v, bool $arr) use (&$fields) { if ($arr) $fields[$n][] = $v; else $fields[$n] = $v; };
            foreach ($f->getElementsByTagName('input') as $i) {
                $n = $i->getAttribute('name'); if ($n === '') continue; $t = strtolower($i->getAttribute('type') ?: 'text'); $names[] = $n;
                if (in_array($t, ['file', 'submit', 'button', 'image'], true)) continue;
                if (in_array($t, ['checkbox', 'radio'], true) && !$i->hasAttribute('checked')) continue;
                $add($n, $i->getAttribute('value') ?: ($t === 'checkbox' ? '1' : ''), str_ends_with($n, '[]'));
            }
            foreach ($f->getElementsByTagName('textarea') as $i) { $n = $i->getAttribute('name'); if ($n !== '') { $names[] = $n; $fields[$n] = $i->textContent; } }
            foreach ($f->getElementsByTagName('select') as $s) {
                $n = $s->getAttribute('name'); if ($n === '') continue; $names[] = $n; $val = null; $first = null;
                foreach ($s->getElementsByTagName('option') as $o) { $first ??= $o->getAttribute('value'); if ($o->hasAttribute('selected')) $val = $o->getAttribute('value'); }
                $fields[$n] = $val ?? $first ?? '';
            }
            $out[] = ['action' => html_entity_decode($f->getAttribute('action')), 'method' => strtolower($f->getAttribute('method') ?: 'get'), 'fields' => $fields, 'names' => $names];
        }
        return $out;
    }

    /** Submit the first form on the CURRENT page whose action contains $needle (overrides replace field values; null removes a field). */
    public function submit(string $needle, array $override = []): string
    {
        foreach ($this->forms() as $f) {
            if (!str_contains($f['action'], $needle)) continue;
            $data = $f['fields'];
            foreach ($override as $k => $v) { if ($v === null) unset($data[$k]); else $data[$k] = $v; }
            $act = $f['action'] === '' ? $this->lastUrl : $f['action'];
            return $this->req($act, $data);
        }
        $this->err = "no form with action containing \"$needle\" on " . $this->lastUrl . ' (forms: ' . implode(', ', array_map(fn($x) => $x['action'], $this->forms())) . ')';
        return '';
    }

    /** Submit the form whose action contains $needle AND whose named fields have the given values (for repeated per-row forms). */
    public function submitWhere(string $needle, array $match, array $override = []): string
    {
        foreach ($this->forms() as $f) {
            if (!str_contains($f['action'], $needle)) continue;
            foreach ($match as $k => $v) if ((string)($f['fields'][$k] ?? '') !== (string)$v) continue 2;
            $data = $f['fields'];
            foreach ($override as $k => $v) { if ($v === null) unset($data[$k]); else $data[$k] = $v; }
            return $this->req($f['action'] === '' ? $this->lastUrl : $f['action'], $data);
        }
        $this->err = "no form with action containing \"$needle\" matching " . json_encode($match) . ' on ' . $this->lastUrl;
        return '';
    }

    /** Submit the first form on the current page that contains a field called $field. */
    public function submitByField(string $field, array $override = []): string
    {
        foreach ($this->forms() as $f) {
            if (!in_array($field, $f['names'], true)) continue;
            $data = $f['fields'];
            foreach ($override as $k => $v) { if ($v === null) unset($data[$k]); else $data[$k] = $v; }
            return $this->req($f['action'] === '' ? $this->lastUrl : $f['action'], $data);
        }
        $this->err = "no form with a \"$field\" field on " . $this->lastUrl;
        return '';
    }

    public function login(string $user, string $pass, string $newPass = '', string $org = ''): bool
    {
        $this->get($this->url('login', $org !== '' ? ['org' => $org] : []));
        $this->submitByField('password', ['username' => $user, 'password' => $pass] + ($org !== '' ? ['org' => $org] : []));
        if (str_contains($this->body, 'One step before you start')) {
            if ($newPass === '') return false;
            $this->submitByField('new2', ['current' => $pass, 'new' => $newPass, 'new2' => $newPass]);
        }
        return !str_contains($this->body, 'Incorrect username or password') && !str_contains($this->title(), 'Sign in');
    }
}
