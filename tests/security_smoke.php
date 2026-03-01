<?php
declare(strict_types=1);

/**
 * Lightweight security smoke checks for RoundDAV suite hardening.
 *
 * Run:
 *   php rounddav/tests/security_smoke.php
 */

$root = dirname(__DIR__);
$suiteRoot = dirname($root);
$failures = [];
$warnings = [];

function write_line(string $message, bool $stderr = false): void
{
    $stream = null;

    if ($stderr && defined('STDERR')) {
        $stream = STDERR;
    } elseif (!$stderr && defined('STDOUT')) {
        $stream = STDOUT;
    } else {
        $uri = $stderr ? 'php://stderr' : 'php://stdout';
        $stream = @fopen($uri, 'wb');
    }

    if (is_resource($stream)) {
        @fwrite($stream, $message);
        if (!defined('STDERR') && !defined('STDOUT')) {
            @fclose($stream);
        }
        return;
    }

    // Final fallback for restrictive SAPIs.
    echo $message;
}

function assert_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (strpos($haystack, $needle) === false) {
        $failures[] = $label . ' (missing: ' . $needle . ')';
    }
}

function assert_not_contains(string $haystack, string $needle, string $label, array &$failures): void
{
    if (strpos($haystack, $needle) !== false) {
        $failures[] = $label . ' (unexpected: ' . $needle . ')';
    }
}

$readFile = static function (array $paths, string $label, bool $required = true) use (&$failures, &$warnings): ?string {
    foreach ($paths as $path) {
        $content = @file_get_contents($path);
        if (is_string($content)) {
            return $content;
        }
    }

    $msg = 'Missing ' . $label . ' (tried: ' . implode(', ', $paths) . ')';
    if ($required) {
        $failures[] = $msg;
    } else {
        $warnings[] = $msg;
    }
    return null;
};

$filesIndex = $readFile(
    [$root . '/public/files/index.php'],
    'rounddav public/files/index.php'
);
$adminIndex = $readFile(
    [$root . '/public/admin/index.php'],
    'rounddav public/admin/index.php'
);
$ssoLogin = $readFile(
    [$root . '/public/sso_login.php'],
    'rounddav public/sso_login.php'
);
$ssoLogout = $readFile(
    [$root . '/public/sso_logout.php'],
    'rounddav public/sso_logout.php'
);
$provisionPlugin = $readFile(
    [
        $suiteRoot . '/rounddav_provision/rounddav_provision.php',
        $root . '/rounddav_provision/rounddav_provision.php',
        $suiteRoot . '/mail/plugins/rounddav_provision/rounddav_provision.php',
        $root . '/mail/plugins/rounddav_provision/rounddav_provision.php',
    ],
    'rounddav_provision plugin',
    false
);
$filesPlugin = $readFile(
    [
        $suiteRoot . '/rounddav_files/rounddav_files.php',
        $root . '/rounddav_files/rounddav_files.php',
        $suiteRoot . '/mail/plugins/rounddav_files/rounddav_files.php',
        $root . '/mail/plugins/rounddav_files/rounddav_files.php',
    ],
    'rounddav_files plugin',
    false
);

if (!is_string($filesIndex) || !is_string($adminIndex) || !is_string($ssoLogin) || !is_string($ssoLogout)) {
    write_line("Security smoke checks cannot run: required RoundDAV core files are missing.\n", true);
    foreach ($failures as $failure) {
        write_line(" - " . $failure . "\n", true);
    }
    exit(2);
}

assert_contains($filesIndex, "rdv_csrf_token_is_valid", "files/index.php should validate CSRF token", $failures);
assert_contains($filesIndex, "name=\"_rdv_csrf\"", "files/index.php forms should include CSRF field", $failures);
assert_contains($adminIndex, "rdv_csrf_token_is_valid", "admin/index.php should validate CSRF token", $failures);
assert_contains($adminIndex, "name=\"_rdv_csrf\"", "admin/index.php forms should include CSRF field", $failures);

assert_not_contains($ssoLogin, "Missing parameters: user=", "sso_login.php should avoid logging raw token parameters", $failures);
assert_not_contains($ssoLogin, "Invalid signature for user=", "sso_login.php should avoid logging raw usernames on signature errors", $failures);
assert_not_contains($ssoLogout, "Invalid signature for user=", "sso_logout.php should avoid logging raw usernames on signature errors", $failures);

if (is_string($provisionPlugin)) {
    assert_not_contains($provisionPlugin, "\$_SESSION['rounddav_pass'] = ", "rounddav_provision should not persist cleartext password into session in authenticate hook", $failures);
}
if (is_string($filesPlugin)) {
    assert_contains($filesPlugin, "resolveExistingPath", "rounddav_files should use canonical path resolution", $failures);
    assert_contains($filesPlugin, "strncmp(\$pathReal, \$basePrefix", "rounddav_files should enforce root prefix boundary checks", $failures);
}

if ($failures) {
    write_line("Security smoke checks failed:\n", true);
    foreach ($failures as $failure) {
        write_line(" - " . $failure . "\n", true);
    }
    exit(1);
}

if ($warnings) {
    write_line("Security smoke checks warnings:\n");
    foreach ($warnings as $warning) {
        write_line(" - " . $warning . "\n");
    }
}

write_line("Security smoke checks passed.\n");
exit(0);
