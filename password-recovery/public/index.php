<?php
declare(strict_types=1);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

session_name('mail_recovery');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/password-recovery/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$configFile = '/var/roundcube/config/password-recovery-config.json';
if (!is_readable($configFile)) {
    http_response_code(503);
    exit('Password recovery is temporarily unavailable.');
}
$config = json_decode((string) file_get_contents($configFile), true, 32, JSON_THROW_ON_ERROR);

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (!isset($config['hosts'][$host])) {
    http_response_code(400);
    exit('Unknown webmail domain.');
}
$brand = $config['hosts'][$host];

try {
    $db = new PDO('sqlite:' . $config['database_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    migrate($db);
    cleanup($db, $config);
} catch (Throwable $error) {
    error_log('Password recovery database error: ' . $error->getMessage());
    http_response_code(503);
    exit('Password recovery is temporarily unavailable.');
}

$action = $_GET['action'] ?? 'home';
$message = null;
$error = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
    }

    switch ($action) {
        case 'enroll':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                enforceRate($db, 'enroll-ip', clientKey(), 5, 3600);
                $mailbox = normalizeMailbox($_POST['mailbox'] ?? '', $brand['domain']);
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $recoveryEmail = strtolower(trim((string) ($_POST['recovery_email'] ?? '')));

                if (!filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new UserError('Enter a valid recovery email address.');
                }
                $recoveryDomain = substr(strrchr($recoveryEmail, '@') ?: '', 1);
                if (in_array($recoveryDomain, $config['hosted_domains'], true)) {
                    throw new UserError('Use an external email address that you can access if this mailbox is locked.');
                }
                if ($currentPassword === '' || !authenticateMailbox($config, $mailbox, $currentPassword)) {
                    throw new UserError('The mailbox or current password is incorrect.');
                }

                $token = randomToken();
                $expires = time() + 1800;
                $statement = $db->prepare('INSERT INTO recovery_enrollments
                    (mailbox, recovery_email, token_hash, expires_at, created_at)
                    VALUES (?, ?, ?, ?, ?)');
                $statement->execute([
                    $mailbox,
                    $recoveryEmail,
                    tokenHash($token),
                    $expires,
                    time(),
                ]);

                $verifyUrl = baseUrl() . '?action=verify&token=' . rawurlencode($token);
                sendMail($config, $recoveryEmail, 'Verify your webmail recovery email',
                    "A recovery email was added for {$mailbox}.\r\n\r\nVerify it within 30 minutes:\r\n{$verifyUrl}\r\n\r\nIf you did not request this, ignore this message.");
                $message = 'Check your recovery email and open the verification link within 30 minutes.';
            }
            render('Set up recovery email', enrollForm($brand), $brand, $message, $error);
            break;

        case 'verify':
            $token = (string) ($_GET['token'] ?? '');
            $record = findEnrollment($db, $token);
            if (!$record || (int) $record['expires_at'] < time()) {
                throw new UserError('This verification link is invalid or has expired. Please enroll again.');
            }

            $db->beginTransaction();
            $statement = $db->prepare('INSERT INTO recovery_accounts
                (mailbox, recovery_email, verified_at, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(mailbox) DO UPDATE SET recovery_email=excluded.recovery_email,
                verified_at=excluded.verified_at, updated_at=excluded.updated_at');
            $statement->execute([
                $record['mailbox'], $record['recovery_email'], time(), time(),
            ]);
            $db->prepare('DELETE FROM recovery_enrollments WHERE id = ?')->execute([$record['id']]);
            $db->commit();

            render('Recovery email verified', '<p>Your recovery email is verified. You can now reset your mailbox password from the webmail login page.</p><p><a class="button" href="/">Return to webmail</a></p>', $brand);
            break;

        case 'forgot':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                enforceRate($db, 'forgot-ip', clientKey(), 8, 3600);
                $mailbox = normalizeMailbox($_POST['mailbox'] ?? '', $brand['domain']);
                enforceRate($db, 'forgot-account', hash('sha256', $mailbox), 3, 3600);
                $account = recoveryAccount($db, $mailbox);
                if ($account) {
                    $token = randomToken();
                    $db->prepare('UPDATE reset_tokens SET used_at = ? WHERE mailbox = ? AND used_at IS NULL')->execute([time(), $mailbox]);
                    $db->prepare('INSERT INTO reset_tokens (mailbox, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$mailbox, tokenHash($token), time() + 1800, time()]);
                    $resetUrl = baseUrl() . '?action=reset&token=' . rawurlencode($token);
                    sendMail($config, $account['recovery_email'], 'Reset your webmail password',
                        "A password reset was requested for {$mailbox}.\r\n\r\nReset it within 30 minutes:\r\n{$resetUrl}\r\n\r\nIf you did not request this, ignore this message.");
                }
                $message = 'If that mailbox has a verified recovery email, a reset link has been sent.';
            }
            render('Forgot password', forgotForm($brand), $brand, $message, $error);
            break;

        case 'reset':
            $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
            $reset = findReset($db, $token);
            if (!$reset || (int) $reset['expires_at'] < time() || $reset['used_at'] !== null) {
                throw new UserError('This reset link is invalid, expired, or already used.');
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                enforceRate($db, 'reset-ip', clientKey(), 8, 3600);
                $password = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                validatePassword($password, $confirm);
                $account = recoveryAccount($db, $reset['mailbox']);
                if (!$account) {
                    throw new UserError('Password recovery is not configured for this mailbox.');
                }
                resetPassword($config, $reset['mailbox'], $password);
                $db->prepare('UPDATE reset_tokens SET used_at = ? WHERE id = ?')->execute([time(), $reset['id']]);
                sendMail($config, $account['recovery_email'], 'Your webmail password was changed',
                    "The password for {$reset['mailbox']} was changed using self-service recovery.\r\n\r\nIf this was not you, contact your email administrator immediately.");
                render('Password changed', '<p>Your password has been changed. You can now sign in with the new password.</p><p><a class="button" href="/">Sign in to webmail</a></p>', $brand);
                break;
            }
            render('Choose a new password', resetForm($token), $brand);
            break;

        default:
            render('Password help', homeContent(), $brand);
    }
} catch (RateError $rateError) {
    http_response_code(429);
    render('Please wait', '<p>Too many attempts. Please wait and try again later.</p><p><a href="/">Return to webmail</a></p>', $brand);
} catch (UserError $userError) {
    http_response_code(400);
    $back = $action === 'enroll' ? enrollForm($brand) : ($action === 'forgot' ? forgotForm($brand) : '<p><a href="?action=forgot">Request a new reset link</a></p>');
    render('Password help', $back, $brand, null, $userError->getMessage());
} catch (Throwable $fatal) {
    error_log('Password recovery error: ' . $fatal->getMessage());
    http_response_code(500);
    render('Password help', '<p>We could not complete the request. Please try again later.</p>', $brand);
}

class UserError extends RuntimeException {}
class RateError extends RuntimeException {}

function migrate(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS recovery_accounts (
        mailbox TEXT PRIMARY KEY, recovery_email TEXT NOT NULL,
        verified_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS recovery_enrollments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT NOT NULL, recovery_email TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL, used_at INTEGER, created_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (
        bucket TEXT NOT NULL, subject TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS rate_lookup ON rate_limits(bucket, subject, created_at)');
}

function cleanup(PDO $db, array $config): void
{
    if (random_int(1, 20) !== 1) return;
    $db->exec('DELETE FROM recovery_enrollments WHERE expires_at < ' . time());
    $db->exec('DELETE FROM reset_tokens WHERE expires_at < ' . (time() - 86400));
    $db->exec('DELETE FROM rate_limits WHERE created_at < ' . (time() - 86400));
}

function normalizeMailbox(string $value, string $requiredDomain): string
{
    $value = strtolower(trim($value));
    if (!str_contains($value, '@')) $value .= '@' . $requiredDomain;
    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || !str_ends_with($value, '@' . $requiredDomain)) {
        throw new UserError('Enter a valid mailbox for this webmail domain.');
    }
    return $value;
}

function authenticateMailbox(array $config, string $mailbox, string $password): bool
{
    $response = httpRequest($config['stalwart_session_url'], 'GET', $mailbox, $password);
    if ($response['status'] !== 200) return false;
    $data = json_decode($response['body'], true);
    return isset($data['username']) && strtolower($data['username']) === $mailbox;
}

function findMailboxAccount(array $config, string $mailbox): array
{
    $localPart = strstr($mailbox, '@', true);
    if ($localPart === false || $localPart === '') throw new RuntimeException('Invalid mailbox address.');
    $query = jmapPayload('x:Account/query', ['filter' => ['name' => $localPart]], 'find-account');
    $queryData = decodeJmap(httpRequest($config['stalwart_api_url'], 'POST', null, null, $query, $config['stalwart_recovery_api_key']), 'x:Account/query');
    $ids = $queryData['ids'] ?? [];
    if (!$ids) throw new RuntimeException('Mailbox account was not found.');

    $get = jmapPayload('x:Account/get', ['ids' => $ids], 'get-account');
    $getData = decodeJmap(httpRequest($config['stalwart_api_url'], 'POST', null, null, $get, $config['stalwart_recovery_api_key']), 'x:Account/get');
    foreach ($getData['list'] ?? [] as $account) {
        if (strtolower((string) ($account['emailAddress'] ?? '')) === $mailbox) return $account;
    }
    throw new RuntimeException('Mailbox account was not found.');
}

function resetPassword(array $config, string $mailbox, string $newPassword): void
{
    $account = findMailboxAccount($config, $mailbox);
    $credentialId = null;
    foreach ($account['credentials'] ?? [] as $id => $credential) {
        if (($credential['@type'] ?? '') === 'Password') { $credentialId = (string) $id; break; }
    }
    if ($credentialId === null) throw new RuntimeException('Mailbox password credential was not found.');
    $path = 'credentials/' . str_replace(['~', '/'], ['~0', '~1'], $credentialId) . '/secret';
    $payload = jmapPayload('x:Account/set', ['update' => [
        $account['id'] => [$path => $newPassword],
    ]], 'reset-password');
    $response = httpRequest($config['stalwart_api_url'], 'POST', null, null, $payload, $config['stalwart_recovery_api_key']);
    $data = decodeJmap($response, 'x:Account/set');
    if (!empty($data['notUpdated']) || !array_key_exists($account['id'], $data['updated'] ?? [])) {
        throw new RuntimeException('The mail server rejected the password update.');
    }
}

function jmapPayload(string $method, array $arguments, string $callId): array
{
    return ['using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'], 'methodCalls' => [[$method, $arguments, $callId]]];
}

function decodeJmap(array $response, string $expectedMethod): array
{
    if ($response['status'] !== 200) throw new RuntimeException('Mail server HTTP error ' . $response['status']);
    $body = json_decode($response['body'], true);
    $method = $body['methodResponses'][0] ?? null;
    if (!is_array($method) || ($method[0] ?? '') !== $expectedMethod) {
        $description = $method[1]['description'] ?? 'Unexpected mail server response';
        throw new RuntimeException($description);
    }
    return $method[1] ?? [];
}

function httpRequest(string $url, string $method, ?string $username = null, ?string $password = null, ?array $json = null, ?string $bearer = null): array
{
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    if ($username !== null) curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('Cannot connect to the mail service.');
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
}

function sendMail(array $config, string $to, string $subject, string $body): void
{
    $smtp = $config['smtp'];
    $transport = ($smtp['encryption'] ?? '') === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $socket = stream_socket_client($transport . $smtp['host'] . ':' . $smtp['port'], $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Cannot connect to notification mail service.');
    stream_set_timeout($socket, 15);
    smtpExpect($socket, [220]);
    smtpCommand($socket, 'EHLO password-recovery', [250]);
    smtpCommand($socket, 'AUTH PLAIN ' . base64_encode("\0{$smtp['username']}\0{$smtp['password']}"), [235]);
    smtpCommand($socket, 'MAIL FROM:<' . $smtp['from_email'] . '>', [250]);
    smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);
    $headers = [
        'From: ' . $smtp['from_name'] . ' <' . $smtp['from_email'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . $subject,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($smtp['from_email'], '@'), 1) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $body));
    fwrite($socket, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
    smtpExpect($socket, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtpCommand($socket, string $command, array $codes): void { fwrite($socket, $command . "\r\n"); smtpExpect($socket, $codes); }
function smtpExpect($socket, array $codes): void
{
    $line = '';
    do { $part = fgets($socket, 2048); if ($part === false) break; $line .= $part; } while (isset($part[3]) && $part[3] === '-');
    if (!in_array((int) substr($line, 0, 3), $codes, true)) throw new RuntimeException('Notification mail service rejected the request.');
}

function validatePassword(string $password, string $confirm): void
{
    if ($password !== $confirm) throw new UserError('The password confirmation does not match.');
    if (strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        throw new UserError('Use at least 12 characters with uppercase, lowercase, a number, and a symbol.');
    }
}

function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function tokenHash(string $token): string { return hash('sha256', $token); }
function findEnrollment(PDO $db, string $token): ?array { if ($token === '') return null; $s=$db->prepare('SELECT * FROM recovery_enrollments WHERE token_hash=?'); $s->execute([tokenHash($token)]); return $s->fetch() ?: null; }
function findReset(PDO $db, string $token): ?array { if ($token === '') return null; $s=$db->prepare('SELECT * FROM reset_tokens WHERE token_hash=?'); $s->execute([tokenHash($token)]); return $s->fetch() ?: null; }
function recoveryAccount(PDO $db, string $mailbox): ?array { $s=$db->prepare('SELECT * FROM recovery_accounts WHERE mailbox=?'); $s->execute([$mailbox]); return $s->fetch() ?: null; }

function enforceRate(PDO $db, string $bucket, string $subject, int $limit, int $window): void
{
    $since = time() - $window;
    $s=$db->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket=? AND subject=? AND created_at>=?');
    $s->execute([$bucket, $subject, $since]);
    if ((int) $s->fetchColumn() >= $limit) throw new RateError();
    $db->prepare('INSERT INTO rate_limits(bucket,subject,created_at) VALUES(?,?,?)')->execute([$bucket,$subject,time()]);
}
function clientKey(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')); }

function requireCsrf(): void
{
    $provided = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $provided)) throw new UserError('Your session expired. Reload the page and try again.');
}
function csrfField(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function baseUrl(): string { return 'https://' . strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) . '/password-recovery/'; }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function homeContent(): string
{
    return '<p>Choose the help you need.</p><div class="choices"><a class="choice" href="?action=forgot"><strong>Forgot password?</strong><span>Send a secure reset link to your verified recovery email.</span></a><a class="choice" href="?action=enroll"><strong>Set up recovery</strong><span>Verify an external email while you still know your mailbox password.</span></a></div><p class="muted">Already signed in? Change your password under Settings → Password. No recovery email is needed.</p>';
}
function enrollForm(array $brand): string
{
    return '<p>Verify an external email so you can recover your mailbox if you forget the password.</p><form method="post">'.csrfField().'<label>Mailbox</label><input name="mailbox" type="email" placeholder="name@'.e($brand['domain']).'" required autocomplete="username"><label>Current mailbox password</label><input name="current_password" type="password" required autocomplete="current-password"><label>External recovery email</label><input name="recovery_email" type="email" placeholder="yourname@gmail.com" required autocomplete="email"><button type="submit">Send verification link</button></form><p class="muted">Your mailbox password is checked securely and is never stored.</p>';
}
function forgotForm(array $brand): string
{
    return '<p>Enter your mailbox. If recovery is set up, we will send a one-time link to your verified external email.</p><form method="post">'.csrfField().'<label>Mailbox</label><input name="mailbox" type="email" placeholder="name@'.e($brand['domain']).'" required autocomplete="username"><button type="submit">Send reset link</button></form><p class="muted"><a href="?action=enroll">Set up a recovery email</a> while you know your current password.</p>';
}
function resetForm(string $token): string
{
    return '<p>Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p><form method="post">'.csrfField().'<input type="hidden" name="token" value="'.e($token).'"><label>New password</label><input name="new_password" type="password" minlength="12" required autocomplete="new-password"><label>Confirm new password</label><input name="confirm_password" type="password" minlength="12" required autocomplete="new-password"><button type="submit">Change password</button></form>';
}

function render(string $title, string $content, array $brand, ?string $message = null, ?string $error = null): never
{
    $notice = $message ? '<div class="notice success">'.e($message).'</div>' : '';
    $notice .= $error ? '<div class="notice error">'.e($error).'</div>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' · '.e($brand['name']).'</title><style>
    :root{color-scheme:light;--brand:#166534;--brand2:#15803d;--ink:#17211a;--muted:#647067;--line:#dce5de}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(145deg,#eff8f0,#f7faf8 52%,#e5f2e8);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--ink);padding:24px}.card{width:min(100%,520px);background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 55px rgba(20,60,30,.14);padding:32px}.brand{font-weight:750;color:var(--brand);letter-spacing:.01em}h1{font-size:1.7rem;line-height:1.2;margin:.55rem 0 1rem}label{display:block;font-weight:650;margin:16px 0 6px}input{width:100%;font:inherit;padding:12px 13px;border:1px solid #b9c8bc;border-radius:9px}input:focus{outline:3px solid #bbf7d0;border-color:var(--brand2)}button,.button{display:inline-block;width:100%;border:0;border-radius:9px;background:var(--brand);color:#fff;font:inherit;font-weight:700;padding:12px 16px;margin-top:20px;text-align:center;text-decoration:none;cursor:pointer}.notice{padding:11px 13px;border-radius:9px;margin:12px 0}.success{background:#dcfce7;color:#14532d}.error{background:#fee2e2;color:#7f1d1d}.muted{font-size:.91rem;color:var(--muted)}a{color:var(--brand)}.choices{display:grid;gap:12px}.choice{display:grid;gap:3px;border:1px solid var(--line);border-radius:11px;padding:14px;text-decoration:none;color:var(--ink)}.choice:hover{border-color:var(--brand2);background:#f0fdf4}.choice span{color:var(--muted);font-size:.92rem}.back{display:inline-block;margin-top:22px;font-size:.92rem}@media(max-width:540px){.card{padding:24px 20px}}
    </style></head><body><main class="card"><div class="brand">'.e($brand['name']).'</div><h1>'.e($title).'</h1>'.$notice.$content.'<a class="back" href="/">← Back to webmail</a></main></body></html>';
    exit;
}
