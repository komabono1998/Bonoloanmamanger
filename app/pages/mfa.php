<?php
/** Multi-factor authentication (TOTP) enrolment and management for the signed-in user. */
$title = 'Multi-factor authentication';
$u = row('SELECT * FROM users WHERE id = ?', [uid()]);
$required = mfa_required_for($u);
$a = get('a');

if ($a === 'start') post_action(function () {
    $_SESSION['mfa_pending_secret'] = Mfa::newSecret();
    return [null, url('mfa')];
});
if ($a === 'confirm') post_action(function () use ($u) {
    $secret = $_SESSION['mfa_pending_secret'] ?? '';
    must($secret !== '', 'Start the setup again.');
    must(password_verify($_POST['password'] ?? '', $u['password_hash']), 'Your password is incorrect.');
    must(Mfa::verify($secret, (string)post('code')) !== null, 'That code is not valid. Check the time on your phone and try again.');
    [$plain, $hashes] = Mfa::recoveryCodes(8);
    q('UPDATE users SET mfa_enabled = 1, mfa_secret = ?, mfa_recovery = ? WHERE id = ?', [encrypt_secret($secret), $hashes, uid()]);
    unset($_SESSION['mfa_pending_secret'], $_SESSION['mfa_enroll']);
    $_SESSION['recovery_codes'] = $plain;
    audit('MFA enabled', 'Security', 'user', uid());
    return ['Multi-factor authentication is now on.', url('mfa')];
});
if ($a === 'disable') post_action(function () use ($u, $required) {
    must(!$required, 'Multi-factor authentication is mandatory for your role and cannot be turned off.');
    must(password_verify($_POST['password'] ?? '', $u['password_hash']), 'Your password is incorrect.');
    $secret = decrypt_secret($u['mfa_secret']);
    must($secret && Mfa::verify($secret, (string)post('code')) !== null, 'That code is not valid.');
    q('UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_recovery = NULL WHERE id = ?', [uid()]);
    audit('MFA disabled', 'Security', 'user', uid());
    return 'Multi-factor authentication turned off.';
});
if ($a === 'recovery') post_action(function () use ($u) {
    must(password_verify($_POST['password'] ?? '', $u['password_hash']), 'Your password is incorrect.');
    $secret = decrypt_secret($u['mfa_secret']);
    must($secret && Mfa::verify($secret, (string)post('code')) !== null, 'That code is not valid.');
    [$plain, $hashes] = Mfa::recoveryCodes(8);
    q('UPDATE users SET mfa_recovery = ? WHERE id = ?', [$hashes, uid()]);
    $_SESSION['recovery_codes'] = $plain;
    audit('MFA recovery codes regenerated', 'Security', 'user', uid());
    return 'New recovery codes generated. The old ones no longer work.';
});

page_head('Multi-factor authentication', btn('My account', url('account')), 'A second step at sign-in protects the account even if the password is stolen.');
if (!empty($_SESSION['mfa_enroll'])) echo '<div class="notice bad">Your role requires multi-factor authentication. Complete the setup below to continue using the system.</div>';

if (!empty($_SESSION['recovery_codes'])) {
    echo '<div class="card"><h2>Recovery codes — save them now</h2><p class="muted">Each code works once if you lose your phone. They are shown only this one time. Store them somewhere safe (not on the same phone).</p><div class="secret mono">'
        . implode(' &nbsp; ', array_map('e', $_SESSION['recovery_codes'])) . '</div></div>';
    unset($_SESSION['recovery_codes']);
}

if (!$u['mfa_enabled']) {
    $secret = $_SESSION['mfa_pending_secret'] ?? '';
    if (!$secret) {
        echo '<div class="card"><h2>Set up an authenticator app</h2><p>Use any TOTP app (Google Authenticator, Microsoft Authenticator, Authy, FreeOTP…).</p>';
        echo '<form method="post" action="' . e(url('mfa', ['a' => 'start'])) . '">' . csrf_field() . '<button class="btn primary">Begin setup</button></form></div>';
    } else {
        $uri = Mfa::uri($secret, $u['username'], business_name());
        echo '<div class="card"><h2>1. Add the account to your app</h2><p>In the app choose <b>Enter a setup key</b> (or add manually) and type this key. Account name: <b>' . e($u['username']) . '</b>, type: <b>time-based</b>.</p>'
            . '<div class="secret mono">' . e(trim(chunk_split($secret, 4, ' '))) . '</div>'
            . '<p class="muted" style="margin-top:.6rem">Setup link (for apps that accept it): <span class="mono" style="word-break:break-all">' . e($uri) . '</span></p></div>';
        echo '<div class="card"><h2>2. Confirm</h2>';
        form_open(url('mfa', ['a' => 'confirm']), ['attrs' => 'style="grid-template-columns:1fr 1fr"']);
        field('code', '6-digit code from the app', ['required' => true, 'attrs' => 'autocomplete="one-time-code" inputmode="numeric"']);
        field('password', 'Your password', ['type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"']);
        echo '<div class="form-actions">'; submit_btn('Turn on MFA'); echo '</div>'; form_close(); echo '</div>';
    }
} else {
    echo '<div class="card"><h2>Status</h2><p>' . badge('Active') . ' Multi-factor authentication is on for your account.' . ($required ? ' It is mandatory for your role.' : '') . '</p>';
    $left = $u['mfa_recovery'] ? count(json_decode($u['mfa_recovery'], true) ?: []) : 0;
    echo '<p class="muted">Recovery codes remaining: ' . $left . '</p></div>';
    echo '<div class="grid g2"><div class="card"><h2>Generate new recovery codes</h2>';
    form_open(url('mfa', ['a' => 'recovery']), ['attrs' => 'style="grid-template-columns:1fr"']);
    field('code', 'Current 6-digit code', ['required' => true, 'attrs' => 'inputmode="numeric"']); field('password', 'Your password', ['type' => 'password', 'required' => true]);
    echo '<div class="form-actions">'; submit_btn('Generate', 'default'); echo '</div>'; form_close();
    echo '</div>';
    if (!$required) {
        echo '<div class="card"><h2>Turn off</h2>';
        form_open(url('mfa', ['a' => 'disable']), ['attrs' => 'style="grid-template-columns:1fr" data-confirm="Turn off multi-factor authentication?"']);
        field('code', 'Current 6-digit code', ['required' => true, 'attrs' => 'inputmode="numeric"']); field('password', 'Your password', ['type' => 'password', 'required' => true]);
        echo '<div class="form-actions">'; submit_btn('Turn off MFA', 'danger'); echo '</div>'; form_close();
        echo '</div>';
    }
    echo '</div>';
}
