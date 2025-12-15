<?php
declare(strict_types=1);

session_start();

$baseDir    = dirname(__DIR__);
$configFile = $baseDir . '/config/config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    echo 'RoundDAV config missing';
    exit;
}

$config = require $configFile;

$sso = $config['sso'] ?? [];
if (empty($sso['enabled']) || empty($sso['secret'])) {
    error_log('[rounddav sso_login] SSO disabled or secret missing');
    http_response_code(403);
    echo 'SSO disabled';
    exit;
}

$secret = (string)$sso['secret'];
$ttl    = isset($sso['ttl']) ? (int)$sso['ttl'] : 600;

$user = $_GET['user'] ?? '';
$ts   = $_GET['ts']   ?? '';
$sig  = $_GET['sig']  ?? '';

if ($user === '' || $ts === '' || $sig === '') {
    error_log('[rounddav sso_login] Missing parameters: user=' . var_export($user, true) . ', ts=' . var_export($ts, true) . ', sig=' . var_export($sig, true));
    http_response_code(400);
    echo 'Missing SSO parameters';
    exit;
}

if (!ctype_digit((string)$ts) || (time() - (int)$ts) > $ttl) {
    error_log('[rounddav sso_login] Token expired or invalid ts=' . var_export($ts, true) . ', ttl=' . $ttl);
    http_response_code(403);
    echo 'SSO token expired';
    exit;
}

$data     = $user . '|' . $ts;
$expected = hash_hmac('sha256', $data, $secret);

if (!hash_equals($expected, (string)$sig)) {
    error_log('[rounddav sso_login] Invalid signature for user=' . var_export($user, true));
    http_response_code(403);
    echo 'Invalid SSO signature';
    exit;
}

// Mark this RoundDAV session as authenticated for the given user.
// This must match what the rest of RoundDAV / Files UI expects.
error_log('[rounddav sso_login] Successful SSO for user=' . var_export($user, true));
$_SESSION['rounddav_files_user'] = $user;

// Determine where to send the user after SSO login.
// Prefer an explicit public files URL from config if available.
$filesUrl = $config['files']['public_url'] ?? null;
if (!$filesUrl) {
    // Fallback: relative path from /public
    $filesUrl = './files/';
}

error_log('[rounddav sso_login] Redirecting to files URL=' . var_export($filesUrl, true));
header('Location: ' . $filesUrl);
exit;
