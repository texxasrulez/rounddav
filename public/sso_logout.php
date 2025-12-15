<?php
declare(strict_types=1);

session_start();

$baseDir    = dirname(__DIR__);
$configFile = $baseDir . '/config/config.php';

if (!file_exists($configFile)) {
    // Silently ignore if config missing
    exit;
}

$config = require $configFile;

$sso = $config['sso'] ?? [];
if (empty($sso['enabled']) || empty($sso['secret'])) {
    // If SSO disabled, nothing to do
    exit;
}

$secret = (string)$sso['secret'];
$ttl    = isset($sso['ttl']) ? (int)$sso['ttl'] : 600;

$user = $_GET['user'] ?? '';
$ts   = $_GET['ts']   ?? '';
$sig  = $_GET['sig']  ?? '';

if ($user === '' || $ts === '' || $sig === '') {
    error_log('[rounddav sso_logout] Missing parameters');
    // Nothing to do
    exit;
}

if (!ctype_digit((string)$ts) || (time() - (int)$ts) > $ttl) {
    error_log('[rounddav sso_logout] Token expired or invalid ts');
    // Token too old, just bail
    exit;
}

$data     = $user . '|' . $ts . '|logout';
$expected = hash_hmac('sha256', $data, $secret);

if (!hash_equals($expected, (string)$sig)) {
    error_log('[rounddav sso_logout] Invalid signature for user=' . var_export($user, true));
    // Invalid signature, ignore
    exit;
}

// Destroy RoundDAV session
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

error_log('[rounddav sso_logout] Destroying session for user=' . var_export($user, true));
session_destroy();
exit;
