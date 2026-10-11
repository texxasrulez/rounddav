<?php
declare(strict_types=1);

// rounddav/public/admin/index.php
//
// Lightweight RoundDAV admin UI
// - Lists principals
// - Shows calendars + addressbooks per principal
// - Create / delete / EDIT calendars
// - Create / delete / EDIT addressbooks
// - Config overview

use RoundDAV\Bookmarks\BookmarkRepository;
use RoundDAV\Bookmarks\BookmarkService;
use RoundDAV\Security\RateLimitGuard;

session_start();

// Autoload + config
$baseDir  = dirname(__DIR__, 2); // public/admin -> public -> base
$autoload = $baseDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('Autoloader not found at ' . htmlspecialchars($autoload));
}
require $autoload;

$configFile = $baseDir . '/config/config.php';
if (!file_exists($configFile)) {
    die('config.php not found at ' . htmlspecialchars($configFile));
}
$config = require $configFile;
if (!defined('RDV_CONFIG_FILE')) {
    define('RDV_CONFIG_FILE', $configFile);
}
if (!defined('RDV_SHARING_CONFIG_FILE')) {
    define('RDV_SHARING_CONFIG_FILE', $baseDir . '/var/admin_sharing_config.json');
}
$rdvSharingConfigCache = null;

function rdv_send_security_headers(array $config): void
{
    $headersCfg = (array) ($config['security']['headers'] ?? []);
    if (array_key_exists('enabled', $headersCfg) && empty($headersCfg['enabled'])) {
        return;
    }

    $sendNosniff = !array_key_exists('x_content_type_options', $headersCfg)
        || !empty($headersCfg['x_content_type_options']);
    if ($sendNosniff) {
        header('X-Content-Type-Options: nosniff');
    }

    $referrerPolicy = trim((string) ($headersCfg['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
    if ($referrerPolicy !== '') {
        header('Referrer-Policy: ' . $referrerPolicy);
    }

    $permissionsPolicy = trim((string) ($headersCfg['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'));
    if ($permissionsPolicy !== '') {
        header('Permissions-Policy: ' . $permissionsPolicy);
    }

    $xfo = trim((string) ($headersCfg['x_frame_options'] ?? ''));
    if ($xfo !== '') {
        header('X-Frame-Options: ' . $xfo);
    }

    $cspCfg = (array) ($headersCfg['csp'] ?? []);
    if (empty($cspCfg['enabled'])) {
        return;
    }

    $policy = trim((string) ($cspCfg['policy'] ?? ''));
    if ($policy === '') {
        $ancestors = $cspCfg['frame_ancestors'] ?? ["'self'"];
        if (is_string($ancestors)) {
            $ancestors = preg_split('/[\s,;]+/', $ancestors) ?: [];
        }
        if (!is_array($ancestors) || !$ancestors) {
            $ancestors = ["'self'"];
        }

        $tokens = [];
        foreach ($ancestors as $item) {
            $token = trim((string) $item);
            if ($token === '') {
                continue;
            }
            if (strtolower($token) === 'self') {
                $token = "'self'";
            }
            $tokens[] = $token;
        }
        if (!$tokens) {
            $tokens = ["'self'"];
        }

        $policy = "default-src 'self'; "
            . "img-src 'self' data: blob:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "frame-ancestors " . implode(' ', $tokens);
    }

    header('Content-Security-Policy: ' . $policy);
}

rdv_send_security_headers($config);

$rate = RateLimitGuard::checkAndConsume($config, 'admin');
foreach (RateLimitGuard::headerMap($rate) as $name => $value) {
    header($name . ': ' . $value);
}
if (empty($rate['allowed'])) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Too Many Requests\n";
    exit;
}

// Database connection
$db = $config['database'] ?? [];
try {
    $pdo = new PDO(
        $db['dsn'] ?? '',
        $db['user'] ?? '',
        $db['password'] ?? '',
        $db['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo 'DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

$bookmarkRepo    = new BookmarkRepository($pdo);
$bookmarkService = new BookmarkService($bookmarkRepo, $config);

// Admin auth config
$adminCfg = $config['admin'] ?? null;
if (!$adminCfg || empty($adminCfg['username']) || empty($adminCfg['password_hash'])) {
    // Minimal safety net: you MUST configure an admin user in config.php
    //
    // 'admin' => [
    //     'username'      => 'admin',
    //     'password_hash' => password_hash('your-password', PASSWORD_DEFAULT),
    // ],
    die('Admin not configured in config.php');
}

// Helpers
function rdv_admin_logged_in(): bool
{
    return !empty($_SESSION['rounddav_admin']);
}

function rdv_require_admin(): void
{
    if (!rdv_admin_logged_in()) {
        header('Location: ?action=login');
        exit;
    }
}

function rdv_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rdv_normalize_host(string $host): string
{
    $host = trim($host);
    if ($host === '') {
        return '';
    }

    if ($host[0] === '[') {
        $end = strpos($host, ']');
        if ($end !== false) {
            return strtolower(substr($host, 1, $end - 1));
        }
    }

    return strtolower(trim(explode(':', $host)[0]));
}

function rdv_proxy_headers_trusted(): bool
{
    $security = $GLOBALS['config']['security'] ?? [];
    return !empty($security['trust_proxy_headers']);
}

function rdv_request_hosts(): array
{
    $hosts = [];

    $host = rdv_normalize_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $hosts[] = $host;
    }

    if (rdv_proxy_headers_trusted()) {
        $xfHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($xfHost !== '') {
            foreach (explode(',', $xfHost) as $item) {
                $candidate = rdv_normalize_host($item);
                if ($candidate !== '') {
                    $hosts[] = $candidate;
                }
            }
        }
    }

    return array_values(array_unique($hosts));
}

function rdv_host_matches_url(string $url, array $hosts): bool
{
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $urlHost = strtolower((string) $parts['host']);
    if ($urlHost === '' || !$hosts) {
        return false;
    }

    return in_array($urlHost, $hosts, true);
}

function rdv_request_is_same_origin(): bool
{
    $hosts = rdv_request_hosts();
    if (!$hosts) {
        return true;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        return rdv_host_matches_url($origin, $hosts);
    }

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        return rdv_host_matches_url($referer, $hosts);
    }

    $secFetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($secFetchSite === 'cross-site') {
        return false;
    }

    return true;
}

function rdv_require_safe_post_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    if (!rdv_request_is_same_origin()) {
        http_response_code(403);
        echo 'Cross-site POST denied';
        exit;
    }

    $token = (string) ($_POST['_rdv_csrf'] ?? '');
    if (!rdv_csrf_token_is_valid($token)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
}

function rdv_csrf_token(): string
{
    $token = $_SESSION['rounddav_admin_csrf'] ?? '';
    if (!is_string($token) || $token === '') {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = hash('sha256', uniqid('rdv_admin_csrf', true) . microtime(true));
        }
        $_SESSION['rounddav_admin_csrf'] = $token;
    }

    return $token;
}

function rdv_csrf_token_is_valid(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $expected = $_SESSION['rounddav_admin_csrf'] ?? '';
    return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

// Fetch principals
function rdv_fetch_principals(PDO $pdo): array
{
    $sql = 'SELECT id, uri, email, displayname FROM principals ORDER BY id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch a single principal
function rdv_fetch_principal(PDO $pdo, int $id): ?array
{
    $sql  = 'SELECT id, uri, email, displayname FROM principals WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Calendars for a principal (calendarinstances + calendars)
function rdv_fetch_calendars(PDO $pdo, string $principalUri): array
{
    $sql = 'SELECT ci.id,
                   ci.calendarid,
                   ci.uri,
                   ci.displayname,
                   ci.description,
                   ci.calendarorder,
                   ci.calendarcolor,
                   ci.transparent,
                   c.components
            FROM calendarinstances ci
            JOIN calendars c ON ci.calendarid = c.id
            WHERE ci.principaluri = :puri
            ORDER BY ci.calendarorder, ci.displayname';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':puri' => $principalUri]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Addressbooks for a principal
function rdv_fetch_addressbooks(PDO $pdo, string $principalUri): array
{
    $sql = 'SELECT id, uri, displayname, description
            FROM addressbooks
            WHERE principaluri = :puri
            ORDER BY displayname';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':puri' => $principalUri]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_bookmark_domain_label(?string $domain): string
{
    if ($domain === null || $domain === '' || $domain === BookmarkService::LOCAL_DOMAIN) {
        return 'Local users (no domain)';
    }

    return rdv_html($domain);
}

function rdv_bookmark_domain_form_value(?string $domain): string
{
    if ($domain === null || $domain === '' || $domain === BookmarkService::LOCAL_DOMAIN) {
        return '';
    }

    return (string) $domain;
}

// Create calendar (components: VEVENT / VTODO / VEVENT,VTODO)
// *** UPDATED for Sabre 4.x schema: calendars + calendarinstances ***
function rdv_create_calendar(PDO $pdo, string $principalUri, string $displayName, string $components = 'VEVENT,VTODO'): void
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        throw new RuntimeException('Display name is required');
    }

    $components = strtoupper(trim($components));
    if ($components === '') {
        $components = 'VEVENT,VTODO';
    }

    // slug for uri
    $slug = preg_replace('~[^a-zA-Z0-9_-]+~', '-', strtolower($displayName));
    if ($slug === '' || $slug === '-') {
        $slug = 'calendar-' . time();
    }

    // avoid duplicate (principaluri, uri) in calendarinstances
    $check = $pdo->prepare(
        'SELECT id FROM calendarinstances WHERE principaluri = :puri AND uri = :uri'
    );
    $check->execute([
        ':puri' => $principalUri,
        ':uri'  => $slug,
    ]);
    if ($check->fetchColumn() !== false) {
        throw new RuntimeException('A calendar with that URI already exists for this principal');
    }

    // calendars: only synctoken + components in new schema
    $insertCal = 'INSERT INTO calendars (synctoken, components)
                  VALUES (1, :components)';
    $stmt      = $pdo->prepare($insertCal);
    $stmt->execute([
        ':components' => $components,
    ]);
    $calendarId = (int) $pdo->lastInsertId();

    // instance
    $insertInst = 'INSERT INTO calendarinstances
                   (calendarid, principaluri, access, displayname, uri, description,
                    calendarorder, calendarcolor, timezone, transparent)
                   VALUES
                   (:calid, :puri, 1, :displayname, :uri, :description,
                    0, NULL, NULL, 0)';
    $stmt = $pdo->prepare($insertInst);
    $stmt->execute([
        ':calid'       => $calendarId,
        ':puri'        => $principalUri,
        ':displayname' => $displayName,
        ':uri'         => $slug,
        ':description' => $displayName,
    ]);
}

// Update existing calendar (instance + components)
function rdv_update_calendar(PDO $pdo, int $instanceId, string $displayName, string $components, int $transparent): void
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        throw new RuntimeException('Display name is required');
    }

    $components = strtoupper(trim($components));
    if ($components === '') {
        $components = 'VEVENT,VTODO';
    }

    // find calendarid
    $sql  = 'SELECT calendarid FROM calendarinstances WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $instanceId]);
    $calId = $stmt->fetchColumn();
    if ($calId === false) {
        throw new RuntimeException('Calendar instance not found');
    }
    $calId = (int) $calId;

    // update instance displayname/transparent/description
    $upd = 'UPDATE calendarinstances
            SET displayname = :displayname,
                description = :description,
                transparent = :transparent
            WHERE id = :id';
    $stmt = $pdo->prepare($upd);
    $stmt->execute([
        ':displayname' => $displayName,
        ':description' => $displayName,
        ':transparent' => $transparent ? 1 : 0,
        ':id'          => $instanceId,
    ]);

    // update components on calendars table
    $updCal = 'UPDATE calendars SET components = :components WHERE id = :id';
    $stmt   = $pdo->prepare($updCal);
    $stmt->execute([
        ':components' => $components,
        ':id'         => $calId,
    ]);
}

// Delete calendar instance + events
function rdv_delete_calendar_instance(PDO $pdo, int $instanceId): void
{
    $sql  = 'SELECT calendarid FROM calendarinstances WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $instanceId]);
    $calId = $stmt->fetchColumn();
    if ($calId === false) {
        return;
    }
    $calId = (int) $calId;

    // delete events + changes
    $del = $pdo->prepare('DELETE FROM calendarobjects WHERE calendarid = :cid');
    $del->execute([':cid' => $calId]);

    $del = $pdo->prepare('DELETE FROM calendarchanges WHERE calendarid = :cid');
    $del->execute([':cid' => $calId]);

    // delete instance
    $del = $pdo->prepare('DELETE FROM calendarinstances WHERE id = :id');
    $del->execute([':id' => $instanceId]);

    // drop calendar row if no remaining instances
    $check = $pdo->prepare('SELECT COUNT(*) FROM calendarinstances WHERE calendarid = :cid');
    $check->execute([':cid' => $calId]);
    if ((int) $check->fetchColumn() === 0) {
        $del = $pdo->prepare('DELETE FROM calendars WHERE id = :cid');
        $del->execute([':cid' => $calId]);
    }
}

// Create addressbook
function rdv_create_addressbook(PDO $pdo, string $principalUri, string $displayName): void
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        throw new RuntimeException('Display name is required');
    }

    $slug = preg_replace('~[^a-zA-Z0-9_-]+~', '-', strtolower($displayName));
    if ($slug === '' || $slug === '-') {
        $slug = 'addressbook-' . time();
    }

    $sql = 'INSERT INTO addressbooks
            (principaluri, uri, displayname, description, synctoken)
            VALUES
            (:puri, :uri, :displayname, :description, 1)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':puri'        => $principalUri,
        ':uri'         => $slug,
        ':displayname' => $displayName,
        ':description' => $displayName,
    ]);
}

// NEW: Update existing addressbook (name + description)
function rdv_update_addressbook(PDO $pdo, int $id, string $displayName, string $description = ''): void
{
    $displayName = trim($displayName);
    $description = trim($description);

    if ($displayName === '') {
        throw new RuntimeException('Display name is required');
    }

    $sql = 'UPDATE addressbooks
            SET displayname = :displayname,
                description = :description
            WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':displayname' => $displayName,
        ':description' => $description,
        ':id'          => $id,
    ]);
}

// Delete addressbook + cards
function rdv_delete_addressbook(PDO $pdo, int $id): void
{
    $sql  = 'SELECT id FROM addressbooks WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $abId = $stmt->fetchColumn();
    if ($abId === false) {
        return;
    }
    $abId = (int) $abId;

    $del = $pdo->prepare('DELETE FROM cards WHERE addressbookid = :id');
    $del->execute([':id' => $abId]);

    $del = $pdo->prepare('DELETE FROM addressbookchanges WHERE addressbookid = :id');
    $del->execute([':id' => $abId]);

    $del = $pdo->prepare('DELETE FROM addressbooks WHERE id = :id');
    $del->execute([':id' => $abId]);
}

// Global/shared collections helpers
function rdv_fetch_global_collections(PDO $pdo): array
{
    $sql = 'SELECT id, type, uri, displayname, description
            FROM rounddav_global_collections
            ORDER BY type, uri';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_fetch_global_permissions(PDO $pdo, int $collectionId): array
{
    $sql  = 'SELECT id, principal_uri, read_only
             FROM rounddav_global_permissions
             WHERE collection_id = :cid
             ORDER BY principal_uri';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $collectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rdv_count_global_permissions(PDO $pdo, int $collectionId): int
{
    $sql  = 'SELECT COUNT(*) FROM rounddav_global_permissions WHERE collection_id = :cid';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $collectionId]);
    return (int) $stmt->fetchColumn();
}

function rdv_create_global_collection(PDO $pdo, string $type, string $uri, string $displayName, string $description): int
{
    $type = $type === 'addressbook' ? 'addressbook' : 'calendar';
    $uri  = trim($uri);

    if ($uri === '') {
        throw new RuntimeException('Collection URI is required');
    }

    $check = $pdo->prepare('SELECT id FROM rounddav_global_collections WHERE uri = :uri');
    $check->execute([':uri' => $uri]);
    if ($check->fetchColumn() !== false) {
        throw new RuntimeException('A global collection with that URI already exists');
    }

    $insert = $pdo->prepare(
        'INSERT INTO rounddav_global_collections (type, uri, displayname, description)
         VALUES (:type, :uri, :displayname, :description)'
    );
    $insert->execute([
        ':type'        => $type,
        ':uri'         => $uri,
        ':displayname' => $displayName !== '' ? $displayName : null,
        ':description' => $description !== '' ? $description : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function rdv_delete_global_collection(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT type FROM rounddav_global_collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $type = $stmt->fetchColumn();

    $del = $pdo->prepare('DELETE FROM rounddav_global_collections WHERE id = :id');
    $del->execute([':id' => $id]);

    if ($type !== false) {
        rdv_delete_collection_share_settings($id);
    }
}

function rdv_add_global_permission(PDO $pdo, int $collectionId, string $principalUri, bool $readOnly): void
{
    $principalUri = trim($principalUri);
    if ($principalUri === '') {
        throw new RuntimeException('Principal URI is required');
    }

    $check = $pdo->prepare(
        'SELECT id FROM rounddav_global_permissions
         WHERE collection_id = :cid AND principal_uri = :puri'
    );
    $check->execute([
        ':cid'  => $collectionId,
        ':puri' => $principalUri,
    ]);
    if ($check->fetchColumn() !== false) {
        throw new RuntimeException('That principal already has a rule for this collection');
    }

    $insert = $pdo->prepare(
        'INSERT INTO rounddav_global_permissions (collection_id, principal_uri, read_only)
         VALUES (:cid, :puri, :ro)'
    );
    $insert->execute([
        ':cid'  => $collectionId,
        ':puri' => $principalUri,
        ':ro'   => $readOnly ? 1 : 0,
    ]);
}

function rdv_delete_global_permission(PDO $pdo, int $id): void
{
    $del = $pdo->prepare('DELETE FROM rounddav_global_permissions WHERE id = :id');
    $del->execute([':id' => $id]);
}

function rdv_default_sharing_config(): array
{
    return [
        'calendar'    => [],
        'addressbook' => [],
        'files'       => [
            'default_access'      => 'read',
            'default_expiry_days' => 30,
            'allow_external'      => false,
            'notify_owner'        => true,
            'auto_fill_from_user' => true,
            'template'            => 'Hello {{user}}, you can access {{path}} until {{expires}}.',
        ],
    ];
}

function rdv_load_sharing_config(): array
{
    global $rdvSharingConfigCache;
    if (is_array($rdvSharingConfigCache)) {
        return $rdvSharingConfigCache;
    }
    $cfg = rdv_default_sharing_config();
    if (is_readable(RDV_SHARING_CONFIG_FILE)) {
        $json = @file_get_contents(RDV_SHARING_CONFIG_FILE);
        $data = json_decode((string) $json, true);
        if (is_array($data)) {
            $cfg = array_replace_recursive($cfg, $data);
        }
    }
    $rdvSharingConfigCache = $cfg;
    return $cfg;
}

function rdv_save_sharing_config(array $cfg): void
{
    global $rdvSharingConfigCache;
    $dir = dirname(RDV_SHARING_CONFIG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents(RDV_SHARING_CONFIG_FILE, (string) $json) === false) {
        throw new RuntimeException('Unable to write sharing config file');
    }
    $rdvSharingConfigCache = $cfg;
}

function rdv_save_calendar_share_settings(int $collectionId, array $input): void
{
    $allowedComponents = ['VEVENT,VTODO', 'VEVENT', 'VTODO'];
    $components        = strtoupper(trim((string) ($input['components'] ?? 'VEVENT,VTODO')));
    if (!in_array($components, $allowedComponents, true)) {
        $components = 'VEVENT,VTODO';
    }
    $defaultAccess = ($input['default_access'] ?? 'ro') === 'rw' ? 'rw' : 'ro';
    $timezone      = trim((string) ($input['timezone'] ?? 'UTC'));
    if ($timezone === '') {
        $timezone = 'UTC';
    }

    $cfg                               = rdv_load_sharing_config();
    $cfg['calendar'][(string) $collectionId] = [
        'components'    => $components,
        'default_access'=> $defaultAccess,
        'auto_assign'   => !empty($input['auto_assign']),
        'autofill_note' => trim((string) ($input['autofill_note'] ?? '')),
        'timezone'      => $timezone,
    ];
    rdv_save_sharing_config($cfg);
}

function rdv_get_calendar_share_settings(int $collectionId): array
{
    $cfg = rdv_load_sharing_config();
    return $cfg['calendar'][(string) $collectionId] ?? [
        'components'    => 'VEVENT,VTODO',
        'default_access'=> 'ro',
        'auto_assign'   => false,
        'autofill_note' => '',
        'timezone'      => 'UTC',
    ];
}

function rdv_save_addressbook_share_settings(int $collectionId, array $input): void
{
    $syncMode = ($input['sync_mode'] ?? 'ro') === 'rw' ? 'rw' : 'ro';
    $cfg                               = rdv_load_sharing_config();
    $cfg['addressbook'][(string) $collectionId] = [
        'sync_mode'      => $syncMode,
        'auto_assign'    => !empty($input['auto_assign']),
        'auto_complete'  => !empty($input['auto_complete']),
        'autofill_note'  => trim((string) ($input['autofill_note'] ?? '')),
    ];
    rdv_save_sharing_config($cfg);
}

function rdv_get_addressbook_share_settings(int $collectionId): array
{
    $cfg = rdv_load_sharing_config();
    return $cfg['addressbook'][(string) $collectionId] ?? [
        'sync_mode'     => 'ro',
        'auto_assign'   => false,
        'auto_complete' => true,
        'autofill_note' => '',
    ];
}

function rdv_save_file_share_settings(array $input): void
{
    $defaultAccess      = ($input['default_access'] ?? 'read') === 'write' ? 'write' : 'read';
    $expiry             = (int) ($input['default_expiry_days'] ?? 30);
    if ($expiry < 0) {
        $expiry = 0;
    }
    $cfg         = rdv_load_sharing_config();
    $cfg['files'] = [
        'default_access'      => $defaultAccess,
        'default_expiry_days' => $expiry,
        'allow_external'      => !empty($input['allow_external']),
        'notify_owner'        => !empty($input['notify_owner']),
        'auto_fill_from_user' => !empty($input['auto_fill_from_user']),
        'template'            => trim((string) ($input['template'] ?? '')),
    ];
    rdv_save_sharing_config($cfg);
}

function rdv_get_file_share_settings(): array
{
    $cfg = rdv_load_sharing_config();
    return $cfg['files'];
}

function rdv_delete_collection_share_settings(int $collectionId): void
{
    $cfg = rdv_load_sharing_config();
    $key = (string) $collectionId;
    unset($cfg['calendar'][$key], $cfg['addressbook'][$key]);
    rdv_save_sharing_config($cfg);
}

function rdv_write_config_file(array $config, string $configFile): void
{
    $export = var_export($config, true);
    $data   = "<?php\nreturn " . $export . ";\n";
    $dir    = dirname($configFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    rdv_backup_config_file($configFile);
    if (@file_put_contents($configFile, $data, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write config.php');
    }
    $settings = rdv_backup_retention_settings($config);
    if (!empty($settings['auto_prune'])) {
        rdv_prune_config_backups($configFile, $settings);
    }

    // Invalidate OPcache for the written config file
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($configFile, true);
    }
    // Give the file system a tiny fraction of a second to sync 
    usleep(100000); 
}

function rdv_backup_config_file(string $configFile): ?string
{
    if (!is_file($configFile)) {
        return null;
    }

    $backupDir = rdv_config_backup_dir($configFile);
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Unable to create config backup directory.');
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (\Throwable $e) {
        $suffix = substr(sha1(uniqid('cfg', true)), 0, 8);
    }

    $backupFile = rtrim($backupDir, '/')
        . '/config-'
        . date('Ymd-His')
        . '-'
        . $suffix
        . '.php';

    if (!@copy($configFile, $backupFile)) {
        throw new RuntimeException('Unable to create config backup file.');
    }

    return $backupFile;
}

function rdv_config_backup_dir(?string $configFile = null): string
{
    if ($configFile === null || $configFile === '') {
        $configFile = RDV_CONFIG_FILE;
    }

    return dirname($configFile) . '/../var/config_backups';
}

function rdv_list_config_backups(?string $configFile = null, int $limit = 25): array
{
    $backupDir = rdv_config_backup_dir($configFile);
    if (!is_dir($backupDir)) {
        return [];
    }

    $files = glob(rtrim($backupDir, '/') . '/config-*.php') ?: [];
    $items = [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $items[] = [
            'name' => basename($file),
            'path' => $file,
            'mtime' => @filemtime($file) ?: 0,
            'size' => @filesize($file) ?: 0,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });

    if ($limit > 0 && count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    return $items;
}

function rdv_backup_retention_settings(array $config): array
{
    $settings = [];
    if (isset($config['backups']) && is_array($config['backups'])) {
        $settings = $config['backups']['config'] ?? [];
    }
    if (!is_array($settings)) {
        $settings = [];
    }

    $keepLast = (int) ($settings['keep_last'] ?? 30);
    if ($keepLast < 1) {
        $keepLast = 1;
    }
    if ($keepLast > 1000) {
        $keepLast = 1000;
    }

    $maxAgeDays = (int) ($settings['max_age_days'] ?? 0);
    if ($maxAgeDays < 0) {
        $maxAgeDays = 0;
    }
    if ($maxAgeDays > 3650) {
        $maxAgeDays = 3650;
    }

    $autoPrune = true;
    if (array_key_exists('auto_prune', $settings)) {
        $autoPrune = !empty($settings['auto_prune']);
    }

    return [
        'keep_last' => $keepLast,
        'max_age_days' => $maxAgeDays,
        'auto_prune' => $autoPrune,
    ];
}

function rdv_prune_config_backups(?string $configFile = null, ?array $settings = null): array
{
    if ($settings === null) {
        $settings = rdv_backup_retention_settings($GLOBALS['config'] ?? []);
    }

    $keepLast = max(1, (int) ($settings['keep_last'] ?? 30));
    $maxAgeDays = max(0, (int) ($settings['max_age_days'] ?? 0));
    $backups = rdv_list_config_backups($configFile, 0);
    if (!$backups) {
        return ['removed' => 0, 'failed' => 0];
    }

    $deleteMap = [];
    if ($keepLast > 0 && count($backups) > $keepLast) {
        foreach (array_slice($backups, $keepLast) as $item) {
            if (!empty($item['path'])) {
                $deleteMap[(string) $item['path']] = true;
            }
        }
    }

    if ($maxAgeDays > 0) {
        $cutoff = time() - ($maxAgeDays * 86400);
        foreach ($backups as $item) {
            $mtime = (int) ($item['mtime'] ?? 0);
            if ($mtime > 0 && $mtime < $cutoff && !empty($item['path'])) {
                $deleteMap[(string) $item['path']] = true;
            }
        }
    }

    if (!$deleteMap) {
        return ['removed' => 0, 'failed' => 0];
    }

    $removed = 0;
    $failed = 0;
    foreach (array_keys($deleteMap) as $path) {
        if (@unlink($path)) {
            $removed++;
        } else {
            $failed++;
        }
    }

    return ['removed' => $removed, 'failed' => $failed];
}

function rdv_resolve_config_backup_file(string $backupName, ?string $configFile = null): string
{
    $name = trim($backupName);
    if ($name === '' || basename($name) !== $name) {
        throw new RuntimeException('Invalid backup name.');
    }
    if (!preg_match('/^config-\d{8}-\d{6}-[a-f0-9]{8}\.php$/i', $name)) {
        throw new RuntimeException('Backup filename format is invalid.');
    }

    $path = rtrim(rdv_config_backup_dir($configFile), '/') . '/' . $name;
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Backup file was not found.');
    }

    return $path;
}

function rdv_snapshot_sanitize(array $config, bool $includeSecrets): array
{
    if ($includeSecrets) {
        return $config;
    }

    $out = $config;
    $mask = '***redacted***';

    if (isset($out['database']) && is_array($out['database']) && array_key_exists('password', $out['database'])) {
        $out['database']['password'] = $mask;
    }
    if (isset($out['admin']) && is_array($out['admin'])) {
        if (array_key_exists('password_hash', $out['admin'])) {
            $out['admin']['password_hash'] = $mask;
        }
        if (array_key_exists('password', $out['admin'])) {
            $out['admin']['password'] = $mask;
        }
    }
    if (isset($out['provision']) && is_array($out['provision']) && array_key_exists('shared_secret', $out['provision'])) {
        $out['provision']['shared_secret'] = $mask;
    }
    if (isset($out['sso']) && is_array($out['sso']) && array_key_exists('secret', $out['sso'])) {
        $out['sso']['secret'] = $mask;
    }
    if (isset($out['bookmarks']) && is_array($out['bookmarks']) && array_key_exists('shared_secret', $out['bookmarks'])) {
        $out['bookmarks']['shared_secret'] = $mask;
    }

    return $out;
}

function rdv_parse_config_snapshot(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('Snapshot JSON cannot be empty.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Snapshot JSON is invalid.');
    }

    $config = $decoded;
    if (isset($decoded['config']) && is_array($decoded['config'])) {
        $config = $decoded['config'];
    }

    if (!is_array($config)) {
        throw new RuntimeException('Snapshot must contain a config object.');
    }

    if (empty($config['database']) || !is_array($config['database']) || empty($config['database']['dsn'])) {
        throw new RuntimeException('Snapshot is missing database.dsn.');
    }

    foreach (['log', 'files', 'security', 'provision', 'sso', 'locale', 'admin', 'bookmarks', 'endpoints'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            $config[$section] = [];
        }
    }

    return $config;
}

function rdv_restore_redacted_secrets(array $incoming, array $current): array
{
    $mask = '***redacted***';
    $secretPaths = [
        ['database', 'password'],
        ['admin', 'password_hash'],
        ['admin', 'password'],
        ['provision', 'shared_secret'],
        ['sso', 'secret'],
        ['bookmarks', 'shared_secret'],
    ];

    foreach ($secretPaths as $path) {
        [$section, $key] = $path;
        if (!isset($incoming[$section]) || !is_array($incoming[$section]) || !array_key_exists($key, $incoming[$section])) {
            continue;
        }

        if ((string) $incoming[$section][$key] !== $mask) {
            continue;
        }

        if (isset($current[$section]) && is_array($current[$section]) && array_key_exists($key, $current[$section])) {
            $incoming[$section][$key] = $current[$section][$key];
        } else {
            $incoming[$section][$key] = '';
        }
    }

    return $incoming;
}

function rdv_flatten_snapshot_value($value, string $prefix, array &$out): void
{
    if (is_array($value)) {
        if ($prefix !== '') {
            $out[$prefix] = '[array]';
        }
        if ($value === [] && $prefix !== '') {
            $out[$prefix] = '[]';
        }

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : ($prefix . '.' . (string) $key);
            rdv_flatten_snapshot_value($child, $path, $out);
        }
        return;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $out[$prefix] = $encoded === false ? (string) $value : $encoded;
}

function rdv_snapshot_diff_summary(array $current, array $incoming, int $maxRows = 200): array
{
    $left = [];
    $right = [];
    rdv_flatten_snapshot_value($current, '', $left);
    rdv_flatten_snapshot_value($incoming, '', $right);
    unset($left[''], $right['']);

    $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
    sort($keys, SORT_STRING);

    $rows = [];
    $total = 0;
    foreach ($keys as $key) {
        $hasLeft = array_key_exists($key, $left);
        $hasRight = array_key_exists($key, $right);
        $leftVal = $hasLeft ? $left[$key] : '';
        $rightVal = $hasRight ? $right[$key] : '';

        if ($hasLeft && $hasRight && $leftVal === $rightVal) {
            continue;
        }

        $status = 'changed';
        if (!$hasLeft && $hasRight) {
            $status = 'added';
        } elseif ($hasLeft && !$hasRight) {
            $status = 'removed';
        }

        $total++;
        if (count($rows) < $maxRows) {
            $rows[] = [
                'path' => $key,
                'status' => $status,
                'current' => $leftVal,
                'incoming' => $rightVal,
            ];
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'truncated' => $total > $maxRows,
    ];
}

function rdv_generate_secret(int $bytes = 32): string
{
    try {
        return bin2hex(random_bytes(max(16, $bytes)));
    } catch (\Throwable $e) {
        return hash('sha256', uniqid('rounddav-admin', true) . microtime(true));
    }
}

function rdv_apply_config_updates(array $config, array $input): array
{
    foreach (['log', 'files', 'security', 'provision', 'sso', 'locale', 'admin', 'bookmarks', 'endpoints'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            $config[$section] = [];
        }
    }
    if (!isset($config['security']['rate_limit']) || !is_array($config['security']['rate_limit'])) {
        $config['security']['rate_limit'] = [];
    }
    if (!isset($config['security']['headers']) || !is_array($config['security']['headers'])) {
        $config['security']['headers'] = [];
    }
    if (!isset($config['security']['headers']['csp']) || !is_array($config['security']['headers']['csp'])) {
        $config['security']['headers']['csp'] = [];
    }

    $config['debug']   = !empty($input['debug']);
    $config['base_uri'] = trim((string) ($input['base_uri'] ?? ''));

    $config['log']['enabled'] = !empty($input['log_enabled']);
    $logFile                  = trim((string) ($input['log_file'] ?? ''));
    if ($logFile !== '') {
        $config['log']['file'] = $logFile;
    }

    $config['files']['enabled'] = !empty($input['files_enabled']);
    $filesRoot                  = trim((string) ($input['files_root'] ?? ''));
    if ($filesRoot !== '') {
        $config['files']['root'] = $filesRoot;
    }
    $config['files']['allow_public_links'] = !empty($input['files_allow_public_links']);
    $config['files']['default_quota_mb']   = max(0, (int) ($input['files_default_quota'] ?? 0));
    $policy = strtolower(trim((string) ($input['files_shared_write_policy'] ?? 'admins_only')));
    $config['files']['shared_write_policy'] = $policy === 'authenticated_users' ? 'authenticated_users' : 'admins_only';

    $config['security']['rate_limit']['enabled']      = !empty($input['rate_limit_enabled']);
    $config['security']['rate_limit']['max_requests'] = max(1, (int) ($input['rate_limit_max'] ?? 100));
    $config['security']['rate_limit']['window_sec']   = max(1, (int) ($input['rate_limit_window'] ?? 60));
    $config['security']['rate_limit']['block_minutes'] = max(1, (int) ($input['rate_limit_block'] ?? 5));
    $config['security']['trust_proxy_headers']        = !empty($input['trust_proxy_headers']);

    $headers = &$config['security']['headers'];
    $headers['enabled'] = !empty($input['headers_enabled']);
    $headers['x_content_type_options'] = !empty($input['headers_nosniff']);
    $headers['referrer_policy'] = trim((string) ($input['headers_referrer_policy'] ?? 'strict-origin-when-cross-origin'));
    $headers['permissions_policy'] = trim((string) ($input['headers_permissions_policy'] ?? ''));

    $xfo = strtoupper(trim((string) ($input['headers_x_frame_options'] ?? '')));
    if (!in_array($xfo, ['', 'SAMEORIGIN', 'DENY'], true)) {
        $xfo = '';
    }
    $headers['x_frame_options'] = $xfo;

    $headers['csp']['enabled'] = !empty($input['headers_csp_enabled']);
    $headers['csp']['policy'] = trim((string) ($input['headers_csp_policy'] ?? ''));

    $ancestorsRaw = trim((string) ($input['headers_csp_frame_ancestors'] ?? "'self'"));
    $ancestors = preg_split('/[\r\n,]+/', $ancestorsRaw) ?: [];
    $ancestorsOut = [];
    foreach ($ancestors as $item) {
        $token = trim((string) $item);
        if ($token === '') {
            continue;
        }
        if (strcasecmp($token, 'self') === 0) {
            $token = "'self'";
        }
        $ancestorsOut[] = $token;
    }
    if (!$ancestorsOut) {
        $ancestorsOut = ["'self'"];
    }
    $headers['csp']['frame_ancestors'] = array_values(array_unique($ancestorsOut));

    $config['provision']['baseurl']          = trim((string) ($input['provision_baseurl'] ?? ($config['provision']['baseurl'] ?? '')));
    $config['provision']['principal_prefix'] = trim((string) ($input['provision_principal_prefix'] ?? ($config['provision']['principal_prefix'] ?? 'principals')));
    if (!empty($input['generate_provision_secret'])) {
        $config['provision']['shared_secret'] = rdv_generate_secret();
    } else {
        $provisionSecret = trim((string) ($input['provision_shared_secret'] ?? ''));
        if ($provisionSecret !== '') {
            $config['provision']['shared_secret'] = $provisionSecret;
        }
    }

    $config['sso']['enabled'] = !empty($input['sso_enabled']);
    $config['sso']['ttl']     = max(60, (int) ($input['sso_ttl'] ?? 600));
    if (!empty($input['generate_sso_secret'])) {
        $config['sso']['secret'] = rdv_generate_secret();
    } elseif (isset($input['sso_secret'])) {
        $ssoSecret = trim((string) $input['sso_secret']);
        if ($ssoSecret !== '') {
            $config['sso']['secret'] = $ssoSecret;
        }
    }

    if (!empty($input['generate_bookmark_secret'])) {
        $config['bookmarks']['shared_secret'] = rdv_generate_secret();
    } elseif (array_key_exists('bookmarks_shared_secret', $input)) {
        $bookmarkSecret = trim((string) $input['bookmarks_shared_secret']);
        $config['bookmarks']['shared_secret'] = $bookmarkSecret;
    }

    $config['locale']['default'] = trim((string) ($input['locale_default'] ?? ($config['locale']['default'] ?? 'en_US')));
    $config['endpoints']['enable_testdav'] = !empty($input['endpoint_enable_testdav']);

    $adminUser = trim((string) ($input['admin_username'] ?? ''));
    if ($adminUser !== '') {
        $config['admin']['username'] = $adminUser;
    }
    if (array_key_exists('admin_email', $input)) {
        $adminEmail = trim((string) $input['admin_email']);
        $config['admin']['email'] = $adminEmail;
        $config['admin']['password'] = $adminEmail;
    }
    if (!empty($input['admin_password'])) {
        $config['admin']['password_hash'] = password_hash((string) $input['admin_password'], PASSWORD_DEFAULT);
    }

    return $config;
}

function rdv_security_headers_diagnostics(array $config): array
{
    $headers = (array) ($config['security']['headers'] ?? []);
    $csp = (array) ($headers['csp'] ?? []);

    $enabled = !array_key_exists('enabled', $headers) || !empty($headers['enabled']);
    $nosniff = !array_key_exists('x_content_type_options', $headers) || !empty($headers['x_content_type_options']);
    $referrerPolicy = trim((string) ($headers['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
    $permissionsPolicy = trim((string) ($headers['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'));
    $xfo = strtoupper(trim((string) ($headers['x_frame_options'] ?? '')));
    if (!in_array($xfo, ['', 'SAMEORIGIN', 'DENY'], true)) {
        $xfo = '';
    }

    $cspEnabled = !empty($csp['enabled']);
    $cspPolicy = trim((string) ($csp['policy'] ?? ''));
    $ancestors = $csp['frame_ancestors'] ?? ["'self'"];
    if (is_string($ancestors)) {
        $ancestors = preg_split('/[\r\n,]+/', $ancestors) ?: [];
    }
    if (!is_array($ancestors)) {
        $ancestors = ["'self'"];
    }
    $frameAncestors = [];
    foreach ($ancestors as $item) {
        $token = trim((string) $item);
        if ($token !== '') {
            $frameAncestors[] = $token;
        }
    }
    if (!$frameAncestors) {
        $frameAncestors = ["'self'"];
    }

    $warnings = [];
    if (!$enabled) {
        $warnings[] = 'Security headers are globally disabled.';
    }
    if ($xfo !== '') {
        $warnings[] = 'X-Frame-Options is set (' . $xfo . '), which can block Roundcube iframe embedding on different origins.';
    }
    if ($cspEnabled && $cspPolicy !== '') {
        $policyLower = strtolower($cspPolicy);
        if (strpos($policyLower, 'frame-ancestors') === false) {
            $warnings[] = 'Custom CSP policy is active without an explicit frame-ancestors directive.';
        } elseif (strpos($policyLower, 'frame-ancestors') !== false && strpos($policyLower, 'https://') === false && strpos($policyLower, "'self'") !== false) {
            $warnings[] = 'Custom CSP appears self-only; cross-origin Roundcube embedding may fail.';
        }
    } elseif ($cspEnabled) {
        $hasExternalAncestor = false;
        foreach ($frameAncestors as $token) {
            if (preg_match('#^https?://#i', $token)) {
                $hasExternalAncestor = true;
                break;
            }
        }
        if (!$hasExternalAncestor) {
            $warnings[] = 'CSP frame-ancestors currently has no explicit external origin. Add your Roundcube URL for cross-origin embedding.';
        }
    }
    if ($permissionsPolicy === '') {
        $warnings[] = 'Permissions-Policy header is disabled.';
    }

    $crossOriginProfile = implode("\n", [
        "security.headers.enabled = true",
        "security.headers.x_content_type_options = true",
        "security.headers.referrer_policy = strict-origin-when-cross-origin",
        "security.headers.permissions_policy = camera=(), microphone=(), geolocation=(), payment=(), usb=()",
        "security.headers.x_frame_options = (empty)",
        "security.headers.csp.enabled = true",
        "security.headers.csp.policy = (empty)",
        "security.headers.csp.frame_ancestors = 'self', https://mail.example.com",
    ]);

    $sameOriginProfile = implode("\n", [
        "security.headers.enabled = true",
        "security.headers.x_content_type_options = true",
        "security.headers.referrer_policy = strict-origin-when-cross-origin",
        "security.headers.permissions_policy = camera=(), microphone=(), geolocation=(), payment=(), usb=()",
        "security.headers.x_frame_options = SAMEORIGIN",
        "security.headers.csp.enabled = true",
        "security.headers.csp.policy = (empty)",
        "security.headers.csp.frame_ancestors = 'self'",
    ]);

    return [
        'enabled' => $enabled,
        'nosniff' => $nosniff,
        'referrer_policy' => $referrerPolicy,
        'permissions_policy' => $permissionsPolicy,
        'x_frame_options' => $xfo === '' ? '(disabled)' : $xfo,
        'csp_enabled' => $cspEnabled,
        'csp_policy_override' => $cspPolicy !== '' ? 'yes' : 'no',
        'frame_ancestors' => implode(', ', $frameAncestors),
        'warnings' => $warnings,
        'profile_cross_origin' => $crossOriginProfile,
        'profile_same_origin' => $sameOriginProfile,
        'profile_cross_origin_fields' => [
            'headers_enabled' => true,
            'headers_nosniff' => true,
            'headers_referrer_policy' => 'strict-origin-when-cross-origin',
            'headers_permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'headers_x_frame_options' => '',
            'headers_csp_enabled' => true,
            'headers_csp_policy' => '',
            'headers_csp_frame_ancestors' => "'self'\nhttps://mail.example.com",
        ],
        'profile_same_origin_fields' => [
            'headers_enabled' => true,
            'headers_nosniff' => true,
            'headers_referrer_policy' => 'strict-origin-when-cross-origin',
            'headers_permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'headers_x_frame_options' => 'SAMEORIGIN',
            'headers_csp_enabled' => true,
            'headers_csp_policy' => '',
            'headers_csp_frame_ancestors' => "'self'",
        ],
    ];
}

function rdv_expected_security_headers(array $config): array
{
    $headersCfg = (array) ($config['security']['headers'] ?? []);
    if (array_key_exists('enabled', $headersCfg) && empty($headersCfg['enabled'])) {
        return [];
    }

    $expected = [];

    $sendNosniff = !array_key_exists('x_content_type_options', $headersCfg)
        || !empty($headersCfg['x_content_type_options']);
    if ($sendNosniff) {
        $expected['x-content-type-options'] = 'nosniff';
    }

    $referrerPolicy = trim((string) ($headersCfg['referrer_policy'] ?? 'strict-origin-when-cross-origin'));
    if ($referrerPolicy !== '') {
        $expected['referrer-policy'] = $referrerPolicy;
    }

    $permissionsPolicy = trim((string) ($headersCfg['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'));
    if ($permissionsPolicy !== '') {
        $expected['permissions-policy'] = $permissionsPolicy;
    }

    $xfo = strtoupper(trim((string) ($headersCfg['x_frame_options'] ?? '')));
    if (in_array($xfo, ['SAMEORIGIN', 'DENY'], true)) {
        $expected['x-frame-options'] = $xfo;
    }

    $cspCfg = (array) ($headersCfg['csp'] ?? []);
    if (!empty($cspCfg['enabled'])) {
        $policy = trim((string) ($cspCfg['policy'] ?? ''));

        if ($policy === '') {
            $ancestors = $cspCfg['frame_ancestors'] ?? ["'self'"];
            if (is_string($ancestors)) {
                $ancestors = preg_split('/[\s,;]+/', $ancestors) ?: [];
            }
            if (!is_array($ancestors) || !$ancestors) {
                $ancestors = ["'self'"];
            }

            $tokens = [];
            foreach ($ancestors as $item) {
                $token = trim((string) $item);
                if ($token === '') {
                    continue;
                }
                if (strtolower($token) === 'self') {
                    $token = "'self'";
                }
                $tokens[] = $token;
            }
            if (!$tokens) {
                $tokens = ["'self'"];
            }

            $policy = "default-src 'self'; "
                . "img-src 'self' data: blob:; "
                . "style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'unsafe-inline'; "
                . "frame-ancestors " . implode(' ', $tokens);
        }

        $expected['content-security-policy'] = $policy;
    }

    return $expected;
}

function rdv_redirect_action(string $requested): string
{
    $allowed = ['sharing', 'sharing_calendar', 'sharing_addressbook', 'sharing_bookmarks', 'sharing_files'];
    return in_array($requested, $allowed, true) ? $requested : 'sharing';
}

function rdv_rate_limit_dir(): string
{
    return dirname(RDV_CONFIG_FILE) . '/../var/rate_limit';
}

function rdv_apply_rate_limit_updates(array $config, array $input): array
{
    if (!isset($config['security']) || !is_array($config['security'])) {
        $config['security'] = [];
    }
    if (!isset($config['security']['rate_limit']) || !is_array($config['security']['rate_limit'])) {
        $config['security']['rate_limit'] = [];
    }

    $config['security']['rate_limit']['enabled'] = !empty($input['rate_limit_enabled']);
    $config['security']['rate_limit']['max_requests'] = max(1, (int) ($input['rate_limit_max'] ?? 100));
    $config['security']['rate_limit']['window_sec'] = max(1, (int) ($input['rate_limit_window'] ?? 300));
    $config['security']['rate_limit']['block_minutes'] = max(1, (int) ($input['rate_limit_block'] ?? 30));

    $rawAllowlist = (string) ($input['rate_limit_ip_allowlist'] ?? '');
    $allowlist = preg_split('/[\s,;]+/', strtolower($rawAllowlist)) ?: [];
    $allowlist = array_values(array_unique(array_filter(array_map('trim', $allowlist), function ($value) {
        return $value !== '';
    })));
    $config['security']['rate_limit']['ip_allowlist'] = $allowlist;

    $scopes = ['dav', 'api', 'admin', 'files', 'files_attach'];
    $scopeInput = isset($input['scope']) && is_array($input['scope']) ? $input['scope'] : [];
    $overrides = [];
    foreach ($scopes as $scope) {
        $row = isset($scopeInput[$scope]) && is_array($scopeInput[$scope]) ? $scopeInput[$scope] : [];
        $overrides[$scope] = [
            'enabled'       => !empty($row['enabled']),
            'max_requests'  => max(1, (int) ($row['max_requests'] ?? $config['security']['rate_limit']['max_requests'])),
            'window_sec'    => max(1, (int) ($row['window_sec'] ?? $config['security']['rate_limit']['window_sec'])),
            'block_minutes' => max(1, (int) ($row['block_minutes'] ?? $config['security']['rate_limit']['block_minutes'])),
        ];
    }
    $config['security']['rate_limit']['scope_overrides'] = $overrides;

    return $config;
}

function rdv_list_rate_limit_entries(array $config, int $max = 300): array
{
    $dir = rdv_rate_limit_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $now = time();

    $files = glob($dir . '/*.json') ?: [];
    $entries = [];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $raw = @file_get_contents($file);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            continue;
        }

        $scope = strtolower((string) ($data['scope'] ?? 'unknown'));
        if ($scope === 'unknown') {
            $base = basename($file, '.json');
            $parts = explode('_', $base, 2);
            if (!empty($parts[0])) {
                $scope = strtolower((string) $parts[0]);
            }
        }
        $policy = rdv_rate_limit_scope_policy($config, $scope);
        $window = max(1, (int) ($policy['window_sec'] ?? 300));
        $limit = max(1, (int) ($policy['max_requests'] ?? 100));

        $ip = trim((string) ($data['ip'] ?? 'unknown'));
        $windowStart = (int) ($data['window_start'] ?? 0);
        $count = max(0, (int) ($data['count'] ?? 0));
        $blockedUntil = (int) ($data['blocked_until'] ?? 0);
        $updatedAt = (int) ($data['updated_at'] ?? @filemtime($file) ?: 0);

        $isBlocked = $blockedUntil > $now;
        $resetAt = $isBlocked ? $blockedUntil : ($windowStart > 0 ? ($windowStart + $window) : ($now + $window));
        $remaining = $isBlocked ? 0 : max(0, $limit - $count);

        $entries[] = [
            'scope'        => $scope,
            'ip'           => $ip,
            'count'        => $count,
            'remaining'    => $remaining,
            'limit'        => $limit,
            'window_sec'   => $window,
            'window_start' => $windowStart,
            'blocked_until'=> $blockedUntil,
            'is_blocked'   => $isBlocked,
            'reset_at'     => $resetAt,
            'updated_at'   => $updatedAt,
        ];
    }

    usort($entries, function (array $a, array $b): int {
        if ($a['is_blocked'] !== $b['is_blocked']) {
            return $a['is_blocked'] ? -1 : 1;
        }

        return ($b['updated_at'] <=> $a['updated_at']);
    });

    if (count($entries) > $max) {
        $entries = array_slice($entries, 0, $max);
    }

    return $entries;
}

function rdv_rate_limit_scope_policy(array $config, string $scope): array
{
    $rate = (array) ($config['security']['rate_limit'] ?? []);
    $policy = [
        'enabled'       => !empty($rate['enabled']),
        'max_requests'  => max(1, (int) ($rate['max_requests'] ?? 100)),
        'window_sec'    => max(1, (int) ($rate['window_sec'] ?? 300)),
        'block_minutes' => max(1, (int) ($rate['block_minutes'] ?? 30)),
    ];

    $overrides = isset($rate['scope_overrides']) && is_array($rate['scope_overrides']) ? $rate['scope_overrides'] : [];
    $key = strtolower(trim($scope));
    if ($key !== '' && isset($overrides[$key]) && is_array($overrides[$key])) {
        $ovr = $overrides[$key];
        if (array_key_exists('enabled', $ovr)) {
            $policy['enabled'] = !empty($ovr['enabled']);
        }
        if (array_key_exists('max_requests', $ovr)) {
            $policy['max_requests'] = max(1, (int) $ovr['max_requests']);
        }
        if (array_key_exists('window_sec', $ovr)) {
            $policy['window_sec'] = max(1, (int) $ovr['window_sec']);
        }
        if (array_key_exists('block_minutes', $ovr)) {
            $policy['block_minutes'] = max(1, (int) $ovr['block_minutes']);
        }
    }

    return $policy;
}

function rdv_reset_rate_limit_store(): int
{
    $dir = rdv_rate_limit_dir();
    if (!is_dir($dir)) {
        return 0;
    }

    $files = glob($dir . '/*.json') ?: [];
    $removed = 0;
    foreach ($files as $file) {
        if (is_file($file) && @unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

$action = $_GET['action'] ?? 'dashboard';
$error  = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    rdv_require_safe_post_request();
}

// Handle login/logout early
if ($action === 'do_login') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (
        $user === ($adminCfg['username'] ?? '') &&
        !empty($adminCfg['password_hash']) &&
        password_verify($pass, $adminCfg['password_hash'])
    ) {
        session_regenerate_id(true);
        $_SESSION['rounddav_admin'] = $user;
        header('Location: ?action=dashboard');
        exit;
    }
    $error  = 'Invalid admin credentials';
    $action = 'login';
}

if ($action === 'logout') {
    unset($_SESSION['rounddav_admin']);
    header('Location: ?action=login');
    exit;
}

// Handle POST actions (create/update/delete)
if (in_array($action, ['create_calendar', 'delete_calendar', 'update_calendar', 'create_addressbook', 'delete_addressbook', 'update_addressbook'], true)) {
    rdv_require_admin();

    try {
        if ($action === 'create_calendar') {
            $principalUri = $_POST['principal_uri'] ?? '';
            $name         = $_POST['displayname'] ?? '';
            $components   = $_POST['components'] ?? 'VEVENT,VTODO';
            $principalId  = (int) ($_POST['principal_id'] ?? 0);

            rdv_create_calendar($pdo, $principalUri, $name, $components);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }

        if ($action === 'update_calendar') {
            $instanceId  = (int) ($_POST['instance_id'] ?? 0);
            $principalId = (int) ($_POST['principal_id'] ?? 0);
            $name        = $_POST['displayname'] ?? '';
            $components  = $_POST['components'] ?? 'VEVENT,VTODO';
            $transparent = isset($_POST['transparent']) ? 1 : 0;

            rdv_update_calendar($pdo, $instanceId, $name, $components, $transparent);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }

        if ($action === 'delete_calendar') {
            $instanceId  = (int) ($_POST['instance_id'] ?? 0);
            $principalId = (int) ($_POST['principal_id'] ?? 0);

            rdv_delete_calendar_instance($pdo, $instanceId);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }

        if ($action === 'create_addressbook') {
            $principalUri = $_POST['principal_uri'] ?? '';
            $name         = $_POST['displayname'] ?? '';
            $principalId  = (int) ($_POST['principal_id'] ?? 0);

            rdv_create_addressbook($pdo, $principalUri, $name);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }

        if ($action === 'update_addressbook') {
            $id          = (int) ($_POST['addressbook_id'] ?? 0);
            $principalId = (int) ($_POST['principal_id'] ?? 0);
            $name        = $_POST['displayname'] ?? '';
            $desc        = $_POST['description'] ?? '';

            rdv_update_addressbook($pdo, $id, $name, $desc);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }

        if ($action === 'delete_addressbook') {
            $id          = (int) ($_POST['addressbook_id'] ?? 0);
            $principalId = (int) ($_POST['principal_id'] ?? 0);

            rdv_delete_addressbook($pdo, $id);

            header('Location: ?action=view_user&id=' . $principalId);
            exit;
        }
    } catch (\Throwable $e) {
        $error  = $e->getMessage();
        $action = 'dashboard';
    }
} elseif (in_array($action, [
    'create_global_collection',
    'delete_global_collection',
    'add_global_permission',
    'delete_global_permission',
    'update_calendar_share',
    'update_addressbook_share',
], true)) {
    rdv_require_admin();

    try {
        if ($action === 'create_global_collection') {
            $type           = $_POST['type'] ?? 'calendar';
            $uri            = $_POST['uri'] ?? '';
            $displayName    = $_POST['displayname'] ?? '';
            $description    = $_POST['description'] ?? '';
            $redirectAction = rdv_redirect_action((string) ($_POST['redirect_action'] ?? 'sharing'));

            $collectionId = rdv_create_global_collection($pdo, $type, $uri, $displayName, $description);

            if ($type === 'calendar') {
                rdv_save_calendar_share_settings($collectionId, $_POST);
            } elseif ($type === 'addressbook') {
                rdv_save_addressbook_share_settings($collectionId, $_POST);
            }

            header('Location: ?action=' . $redirectAction . '&created=1');
            exit;
        }

        if ($action === 'delete_global_collection') {
            $id = (int) ($_POST['collection_id'] ?? 0);
            if ($id > 0) {
                rdv_delete_global_collection($pdo, $id);
            }

            $redirectAction = rdv_redirect_action((string) ($_POST['redirect_action'] ?? 'sharing'));
            header('Location: ?action=' . $redirectAction);
            exit;
        }

        if ($action === 'add_global_permission') {
            $collectionId = (int) ($_POST['collection_id'] ?? 0);
            $principalUri = $_POST['principal_uri'] ?? '';
            $readOnly     = !empty($_POST['read_only']);
            $allUsers     = !empty($_POST['all_users']);
            $redirectAction = rdv_redirect_action((string) ($_POST['redirect_action'] ?? 'sharing'));

            if ($collectionId <= 0) {
                throw new RuntimeException('Invalid collection ID');
            }

            if ($allUsers) {
                $principalUri = '*';
            }

            rdv_add_global_permission($pdo, $collectionId, $principalUri, $readOnly);

            header('Location: ?action=' . $redirectAction);
            exit;
        }

        if ($action === 'delete_global_permission') {
            $permId = (int) ($_POST['permission_id'] ?? 0);
            if ($permId > 0) {
                rdv_delete_global_permission($pdo, $permId);
            }

            $redirectAction = rdv_redirect_action((string) ($_POST['redirect_action'] ?? 'sharing'));
            header('Location: ?action=' . $redirectAction);
            exit;
        }
        if ($action === 'update_calendar_share') {
            $collectionId = (int) ($_POST['collection_id'] ?? 0);
            if ($collectionId <= 0) {
                throw new RuntimeException('Invalid collection ID');
            }
            rdv_save_calendar_share_settings($collectionId, $_POST);
            header('Location: ?action=sharing_calendar&updated=1');
            exit;
        }
        if ($action === 'update_addressbook_share') {
            $collectionId = (int) ($_POST['collection_id'] ?? 0);
            if ($collectionId <= 0) {
                throw new RuntimeException('Invalid collection ID');
            }
            rdv_save_addressbook_share_settings($collectionId, $_POST);
            header('Location: ?action=sharing_addressbook&updated=1');
            exit;
        }
    } catch (\Throwable $e) {
        $error  = $e->getMessage();
        $action = 'sharing';
    }
} elseif ($action === 'bookmark_domain_save') {
    rdv_require_admin();
    try {
        $bookmarkService->saveDomainSettings((string) ($_POST['domain'] ?? ''), $_POST);
        header('Location: ?action=sharing_bookmarks&saved=1');
        exit;
    } catch (Throwable $e) {
        header('Location: ?action=sharing_bookmarks&error=' . rawurlencode($e->getMessage()));
        exit;
    }
} elseif ($action === 'bookmark_domain_delete') {
    rdv_require_admin();
    try {
        $bookmarkService->deleteDomainSettings((string) ($_POST['domain'] ?? ''));
        header('Location: ?action=sharing_bookmarks&deleted=1');
        exit;
    } catch (Throwable $e) {
        header('Location: ?action=sharing_bookmarks&error=' . rawurlencode($e->getMessage()));
        exit;
    }
} elseif ($action === 'update_file_sharing') {
    rdv_require_admin();
    try {
        rdv_save_file_share_settings($_POST);
        header('Location: ?action=sharing_files&saved=1');
        exit;
    } catch (\Throwable $e) {
        $error  = $e->getMessage();
        $action = 'sharing_files';
    }
} elseif ($action === 'update_rate_limit') {
    rdv_require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $config = rdv_apply_rate_limit_updates($config, $_POST);
            rdv_write_config_file($config, RDV_CONFIG_FILE);
            header('Location: ?action=dashboard&rate_saved=1');
            exit;
        } catch (\Throwable $e) {
            $error  = $e->getMessage();
            $action = 'dashboard';
        }
    } else {
        header('Location: ?action=dashboard');
        exit;
    }
} elseif ($action === 'reset_rate_limit') {
    rdv_require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $removed = rdv_reset_rate_limit_store();
        header('Location: ?action=dashboard&rate_reset=' . $removed);
        exit;
    }
    header('Location: ?action=dashboard');
    exit;
} elseif ($action === 'update_config') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $config = rdv_apply_config_updates($config, $_POST);
            rdv_write_config_file($config, RDV_CONFIG_FILE);
            unset($_SESSION['rdv_config_import_preview']);
            header('Location: ?action=config&saved=1');
            exit;
        } catch (\Throwable $e) {
            $error  = $e->getMessage();
            $action = 'config';
        }
    } else {
        header('Location: ?action=config');
        exit;
    }
} elseif ($action === 'export_config_snapshot') {
    rdv_require_admin();
    $includeSecrets = !empty($_POST['include_secrets']);
    $payload = [
        'generated_at' => date('c'),
        'generated_by' => (string) ($_SESSION['rounddav_admin'] ?? ''),
        'include_secrets' => $includeSecrets,
        'config' => rdv_snapshot_sanitize($config, $includeSecrets),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode snapshot JSON.');
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="rounddav-config-snapshot-' . date('Ymd-His') . '.json"');
    echo $json;
    exit;
} elseif ($action === 'preview_config_snapshot') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $raw = (string) ($_POST['snapshot_json'] ?? '');
        $incoming = rdv_parse_config_snapshot($raw);
        $incoming = rdv_restore_redacted_secrets($incoming, $config);
        $diff = rdv_snapshot_diff_summary($config, $incoming, 200);
        $_SESSION['rdv_config_import_preview'] = [
            'created_at' => time(),
            'diff' => $diff,
            'config' => $incoming,
        ];
        header('Location: ?action=config&import_preview=1');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'apply_config_snapshot') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $preview = $_SESSION['rdv_config_import_preview'] ?? null;
        if (!is_array($preview) || empty($preview['config']) || !is_array($preview['config'])) {
            throw new RuntimeException('No import preview is available.');
        }

        $createdAt = (int) ($preview['created_at'] ?? 0);
        if ($createdAt > 0 && (time() - $createdAt) > 3600) {
            unset($_SESSION['rdv_config_import_preview']);
            throw new RuntimeException('Import preview expired. Please preview the snapshot again.');
        }

        $config = $preview['config'];
        rdv_write_config_file($config, RDV_CONFIG_FILE);
        unset($_SESSION['rdv_config_import_preview']);
        header('Location: ?action=config&saved=1&imported=1');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'cancel_config_snapshot') {
    rdv_require_admin();
    unset($_SESSION['rdv_config_import_preview']);
    header('Location: ?action=config');
    exit;
} elseif ($action === 'create_config_backup') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $backupPath = rdv_backup_config_file(RDV_CONFIG_FILE);
        if ($backupPath === null) {
            throw new RuntimeException('No config.php exists to back up yet.');
        }
        $settings = rdv_backup_retention_settings($config);
        $removed = 0;
        if (!empty($settings['auto_prune'])) {
            $result = rdv_prune_config_backups(RDV_CONFIG_FILE, $settings);
            $removed = (int) ($result['removed'] ?? 0);
        }
        header('Location: ?action=config&backup_created=' . rawurlencode(basename($backupPath)) . '&backup_pruned=' . $removed);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'download_config_backup') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $backupName = (string) ($_POST['backup_name'] ?? '');
        $backupPath = rdv_resolve_config_backup_file($backupName, RDV_CONFIG_FILE);
        header('Content-Type: application/x-php; charset=utf-8');
        header('Content-Length: ' . (string) (@filesize($backupPath) ?: 0));
        header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
        readfile($backupPath);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'download_all_config_backups') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required to download all backups.');
        }

        $items = rdv_list_config_backups(RDV_CONFIG_FILE, 0);
        if (!$items) {
            throw new RuntimeException('No config backups are available.');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'rdvcfgzip-');
        if (!is_string($tmpZip) || $tmpZip === '') {
            throw new RuntimeException('Unable to create temporary archive file.');
        }
        @unlink($tmpZip);
        $tmpZip .= '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open ZIP archive for writing.');
        }

        foreach ($items as $item) {
            $path = (string) ($item['path'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($path === '' || $name === '' || !is_file($path)) {
                continue;
            }
            if (!$zip->addFile($path, $name)) {
                throw new RuntimeException('Unable to add backup to ZIP archive: ' . $name);
            }
        }

        $zip->close();
        if (!is_file($tmpZip)) {
            throw new RuntimeException('Failed to finalize ZIP archive.');
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) (@filesize($tmpZip) ?: 0));
        header('Content-Disposition: attachment; filename="rounddav-config-backups-' . date('Ymd-His') . '.zip"');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    } catch (\Throwable $e) {
        if (isset($tmpZip) && is_string($tmpZip) && $tmpZip !== '' && is_file($tmpZip)) {
            @unlink($tmpZip);
        }
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'restore_config_backup') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $backupName = (string) ($_POST['backup_name'] ?? '');
        $backupPath = rdv_resolve_config_backup_file($backupName, RDV_CONFIG_FILE);
        rdv_backup_config_file(RDV_CONFIG_FILE);
        $restored = @copy($backupPath, RDV_CONFIG_FILE);
        if (!$restored) {
            throw new RuntimeException('Unable to restore selected backup.');
        }

        $settings = rdv_backup_retention_settings($config);
        $removed = 0;
        if (!empty($settings['auto_prune'])) {
            $result = rdv_prune_config_backups(RDV_CONFIG_FILE, $settings);
            $removed = (int) ($result['removed'] ?? 0);
        }

        unset($_SESSION['rdv_config_import_preview']);
        header('Location: ?action=config&saved=1&restored=' . rawurlencode(basename($backupPath)) . '&backup_pruned=' . $removed);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'update_backup_retention') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        if (!isset($config['backups']) || !is_array($config['backups'])) {
            $config['backups'] = [];
        }
        if (!isset($config['backups']['config']) || !is_array($config['backups']['config'])) {
            $config['backups']['config'] = [];
        }

        $keepLast = (int) ($_POST['backup_keep_last'] ?? 30);
        if ($keepLast < 1) {
            $keepLast = 1;
        }
        if ($keepLast > 1000) {
            $keepLast = 1000;
        }

        $maxAgeDays = (int) ($_POST['backup_max_age_days'] ?? 0);
        if ($maxAgeDays < 0) {
            $maxAgeDays = 0;
        }
        if ($maxAgeDays > 3650) {
            $maxAgeDays = 3650;
        }

        $config['backups']['config']['keep_last'] = $keepLast;
        $config['backups']['config']['max_age_days'] = $maxAgeDays;
        $config['backups']['config']['auto_prune'] = !empty($_POST['backup_auto_prune']);

        rdv_write_config_file($config, RDV_CONFIG_FILE);
        unset($_SESSION['rdv_config_import_preview']);
        header('Location: ?action=config&saved=1&backup_policy_saved=1');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
} elseif ($action === 'prune_config_backups') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?action=config');
        exit;
    }

    try {
        $settings = rdv_backup_retention_settings($config);
        $result = rdv_prune_config_backups(RDV_CONFIG_FILE, $settings);
        $removed = (int) ($result['removed'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        header('Location: ?action=config&backup_pruned=' . $removed . '&backup_prune_failed=' . $failed);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $action = 'config';
    }
}

// Render helpers
function rdv_render_header(string $title = 'RoundDAV Admin'): void
{
    $t = rdv_html($title);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $t ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background: #0f172a;
        color: #e5e7eb;
        margin: 0;
        padding: 20px;
    }
    .card {
        max-width: 1100px;
        margin: 0 auto;
        background: #020617;
        border-radius: 16px;
        padding: 20px 24px 28px 24px;
        box-shadow: 0 25px 50px -12px rgba(15,23,42,0.7);
        border: 1px solid #1f2937;
    }
	hr {
	  border: none; /* Removes the default 3D border */
	  height: 1px; /* Sets the thickness of the line */
	  background-color: #1f2937; /* Sets the color of the line */
	  margin: 20px auto; /* Adds spacing and centers the line */
	  width: 100%; /* Adjusts the width of the line */
	}
    .login-logo img {
		height: 40px;
	}
    .admin-logo img {
		height: 25px;
	}
	header .logo-wrap {
		display: flex;
		align-items: center;
		gap: 8px;
	}
	header img {
		height: 40px;
		position: relative;
		top: -4px;
	}
    h1, h2, h3 {
        color: #f9fafb;
        margin-top: 0;
    }
    a {
        color: #60a5fa;
        text-decoration: none;
    }
    a:hover {
        text-decoration: underline;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 12px 0;
        font-size: 14px;
    }
    th, td {
        padding: 6px 8px;
        border-bottom: 1px solid #1f2937;
        text-align: left;
        vertical-align: middle;
    }
    th {
        background: #020617;
        color: #9ca3af;
        font-weight: 500;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .08em;
        background: #1f2937;
        color: #9ca3af;
    }
    .pill-ok {
        background: #065f46;
        color: #bbf7d0;
    }
    .pill-bad {
        background: #7f1d1d;
        color: #fecaca;
    }
    .btn {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 999px;
        background: #2563eb;
        color: #e5e7eb;
        border: none;
        font-size: 13px;
        cursor: pointer;
    }
    .btn.small {
        padding: 3px 8px;
        font-size: 12px;
    }
    .btn.danger {
        background: #b91c1c;
    }
    .btn + .btn {
        margin-left: 6px;
    }
    .alert {
        padding: 10px 14px;
        border-radius: 10px;
        margin: 10px 0;
        font-size: 13px;
        background: #1f2937;
        color: #f3f4f6;
        border: 1px solid #374151;
    }
    .alert.success {
        background: #064e3b;
        border-color: #047857;
        color: #d1fae5;
    }
    .alert.error {
        background: #7f1d1d;
        border-color: #b91c1c;
        color: #fee2e2;
    }
    .nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }
    .share-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }
    .share-card {
        display: block;
        padding: 16px;
        border-radius: 12px;
        background: #0f172a;
        border: 1px solid #1f2937;
        color: #e5e7eb;
        text-decoration: none;
        box-shadow: 0 15px 25px rgba(15,23,42,0.35);
    }
    .share-card:hover {
        border-color: #2563eb;
    }
    .share-card h3 {
        margin: 0 0 6px;
        font-size: 18px;
    }
    .share-card p {
        margin: 0;
        color: #9ca3af;
        font-size: 13px;
    }
    .share-card strong {
        display: block;
        margin-top: 6px;
        letter-spacing: .08em;
        color: #bfdbfe;
    }
    .share-config {
        background: #0b1120;
        border: 1px solid #1f2937;
        border-radius: 14px;
        padding: 18px;
        margin-bottom: 20px;
    }
    .share-config h3 {
        margin-top: 0;
    }
    .form-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
    }
    .form-card {
        flex: 1 1 320px;
        background: #0b1120;
        border: 1px solid #1f2937;
        border-radius: 12px;
        padding: 16px;
    }
    .form-card h3 {
        margin-top: 0;
    }
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
    }
    .form-card.full-width {
        grid-column: 1 / -1;
    }
    .security-headers-columns {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }
    .security-headers-column {
        min-width: 0;
    }
    @media (max-width: 980px) {
        .security-headers-columns {
            grid-template-columns: 1fr;
        }
    }
    .stacked-form label {
        display: block;
        margin-bottom: 10px;
    }
    .stacked-form textarea {
        width: 100%;
        min-height: 70px;
        background: #020617;
        border: 1px solid #1f2937;
        border-radius: 8px;
        color: #e5e7eb;
        padding: 6px 8px;
    }
    .nav-links a {
		top: -10px;
        margin-right: 10px;
        font-size: 13px;
    }
    .muted {
        color: #9ca3af;
        font-size: 13px;
    }
    form.inline {
        display: inline;
    }
	input[type="text"],
	input[type="password"] {
        background: #020617;
        border-radius: 999px;
        border: 1px solid #1f2937;
        padding: 4px 8px;
        color: #e5e7eb;
        font-size: 13px;
    }
    select {
        background: #020617;
        border-radius: 999px;
        border: 1px solid #1f2937;
        padding: 3px 8px;
        color: #e5e7eb;
        font-size: 13px;
    }
    .error {
        color: #fecaca;
        background: #7f1d1d;
        border-radius: 8px;
        padding: 6px 8px;
        font-size: 13px;
        margin-bottom: 10px;
    }
</style>
</head>
<body>
<div class="card">
<?php
}

function rdv_render_footer(): void
{
    echo "</div></body></html>";
}

// Login screen
if ($action === 'login' && !rdv_admin_logged_in()) {
    rdv_render_header('RoundDAV Admin Login');
    ?>

<div class="login-logo">
	<img src="../assets/admin-logo-rounddav.svg" alt="RoundDAV logo" />
</div>

<h1>Administration Panel</h1>
<p class="muted">Sign in to manage principals, calendars, and addressbooks.</p>
<?php if (!empty($error)): ?>
  <div class="error"><?= rdv_html($error) ?></div>
<?php endif; ?>
<form method="post" action="?action=do_login">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
  <p>
    <label>Username<br>
      <input type="text" name="username">
    </label>
  </p>
  <p>
    <label>Password<br>
      <input type="password" name="password">
    </label>
  </p>
  <p><button class="btn" type="submit">Sign in</button></p>
</form>
<?php
    rdv_render_footer();
    exit;
}

// Everything else requires admin
rdv_require_admin();

rdv_render_header('RoundDAV Admin');
?>

	

<div class="nav">
  <div class="admin-logo"><img src="../assets/admin-logo-rounddav.svg" alt="RoundDAV logo" /><strong>Admin</strong> <span class="muted">/ <?= rdv_html($action) ?></span></div>
  <div class="nav-links">
    <a href="../files/index.php">Files</a>
    <a href="?action=dashboard">Dashboard</a>
    <a href="?action=sharing">Sharing</a>
    <a href="?action=config">Config</a>
    <a href="?action=logout">Logout</a>
  </div>
</div>

<?php if (!empty($error)): ?>
  <div class="error"><?= rdv_html($error) ?></div>
<?php endif; ?>

<?php
// Dashboard: principals
if ($action === 'dashboard'): ?>
<hr>
<h2>Principals</h2>
<p class="muted">
  These are the SabreDAV principals. Users are typically in the form
  <code>principals/users/username</code>.
</p>
<?php
    $principals = rdv_fetch_principals($pdo);
    if (!$principals): ?>
  <p class="muted">No principals found.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>URI</th>
        <th>Email</th>
        <th>Display Name</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($principals as $p): ?>
      <tr>
        <td><?= (int) $p['id'] ?></td>
        <td><?= rdv_html((string) $p['uri']) ?></td>
        <td><?= rdv_html((string) ($p['email'] ?? '')) ?></td>
        <td><?= rdv_html((string) ($p['displayname'] ?? '')) ?></td>
        <td>
          <a class="btn small" href="?action=view_user&id=<?= (int) $p['id'] ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<hr>
<h2>Rate limits</h2>
<p class="muted">
  Live counters are read from <code><?= rdv_html(rdv_rate_limit_dir()) ?></code>.
  Entries update as requests hit DAV/API/admin/files endpoints.
</p>
<?php
  $rateCfg = $config['security']['rate_limit'] ?? [];
  $rateScopes = ['dav', 'api', 'admin', 'files', 'files_attach'];
  $scopeOverrides = isset($rateCfg['scope_overrides']) && is_array($rateCfg['scope_overrides']) ? $rateCfg['scope_overrides'] : [];
  $allowlist = isset($rateCfg['ip_allowlist']) && is_array($rateCfg['ip_allowlist']) ? $rateCfg['ip_allowlist'] : [];
  $allowlistText = implode("\n", $allowlist);
  $rateEntries = rdv_list_rate_limit_entries($config, 300);
  $blockedCount = 0;
  foreach ($rateEntries as $entry) {
      if (!empty($entry['is_blocked'])) {
          $blockedCount++;
      }
  }
  if (!empty($_GET['rate_saved'])): ?>
  <div class="alert success">Rate-limit settings saved.</div>
<?php endif; ?>
<?php if (isset($_GET['rate_reset'])): ?>
  <div class="alert success">
    Cleared <?= (int) $_GET['rate_reset'] ?> limiter state file(s).
  </div>
<?php endif; ?>

<div class="share-config">
  <h3>Current policy</h3>
  <p class="muted">
    <strong>Status:</strong>
    <?php if (!empty($rateCfg['enabled'])): ?>
      <span class="pill pill-ok">Enabled</span>
    <?php else: ?>
      <span class="pill pill-bad">Disabled</span>
    <?php endif; ?>
    · <strong>Limit:</strong> <?= (int) ($rateCfg['max_requests'] ?? 100) ?>/<?= (int) ($rateCfg['window_sec'] ?? 300) ?>s
    · <strong>Block:</strong> <?= (int) ($rateCfg['block_minutes'] ?? 30) ?> minute(s)
    · <strong>Allowlisted IP rules:</strong> <?= count($allowlist) ?>
    · <strong>Tracked entries:</strong> <?= count($rateEntries) ?>
    · <strong>Blocked now:</strong> <?= $blockedCount ?>
  </p>
  <form method="post" action="?action=update_rate_limit">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
    <p>
      <label>
        <input type="checkbox" name="rate_limit_enabled" value="1" <?= !empty($rateCfg['enabled']) ? 'checked' : '' ?>>
        Enable rate limiting
      </label>
    </p>
    <p>
      <label>Max requests
        <input type="number" min="1" name="rate_limit_max" value="<?= (int) ($rateCfg['max_requests'] ?? 100) ?>">
      </label>
      <label style="margin-left:8px;">Window (seconds)
        <input type="number" min="1" name="rate_limit_window" value="<?= (int) ($rateCfg['window_sec'] ?? 300) ?>">
      </label>
      <label style="margin-left:8px;">Block (minutes)
        <input type="number" min="1" name="rate_limit_block" value="<?= (int) ($rateCfg['block_minutes'] ?? 30) ?>">
      </label>
    </p>
    <p>
      <label>IP allowlist (one IP or CIDR per line)<br>
        <textarea name="rate_limit_ip_allowlist" rows="4" style="width:100%;background:#020617;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;"><?= rdv_html($allowlistText) ?></textarea>
      </label>
      <span class="muted">Examples: <code>127.0.0.1</code>, <code>192.168.1.0/24</code>, <code>2001:db8::/32</code></span>
    </p>
    <p><strong>Per-scope overrides</strong></p>
    <table>
      <thead>
        <tr>
          <th>Scope</th>
          <th>Enabled</th>
          <th>Max requests</th>
          <th>Window (sec)</th>
          <th>Block (min)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rateScopes as $scope): ?>
        <?php
          $scopeCfg = isset($scopeOverrides[$scope]) && is_array($scopeOverrides[$scope]) ? $scopeOverrides[$scope] : [];
        ?>
        <tr>
          <td><code><?= rdv_html($scope) ?></code></td>
          <td>
            <input type="checkbox" name="scope[<?= rdv_html($scope) ?>][enabled]" value="1" <?= !empty($scopeCfg['enabled']) ? 'checked' : '' ?>>
          </td>
          <td>
            <input type="number" min="1" name="scope[<?= rdv_html($scope) ?>][max_requests]" value="<?= (int) ($scopeCfg['max_requests'] ?? ($rateCfg['max_requests'] ?? 100)) ?>">
          </td>
          <td>
            <input type="number" min="1" name="scope[<?= rdv_html($scope) ?>][window_sec]" value="<?= (int) ($scopeCfg['window_sec'] ?? ($rateCfg['window_sec'] ?? 300)) ?>">
          </td>
          <td>
            <input type="number" min="1" name="scope[<?= rdv_html($scope) ?>][block_minutes]" value="<?= (int) ($scopeCfg['block_minutes'] ?? ($rateCfg['block_minutes'] ?? 30)) ?>">
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p>
      <button class="btn small" type="submit">Save rate limits</button>
    </p>
  </form>
  <form method="post" action="?action=reset_rate_limit" onsubmit="return confirm('Clear all live rate-limit counters now?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
    <button class="btn small danger" type="submit">Clear live counters</button>
  </form>
</div>

<?php if (!$rateEntries): ?>
  <p class="muted">No live rate-limit entries yet.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Scope</th>
        <th>Policy</th>
        <th>Client IP</th>
        <th>Status</th>
        <th>Count</th>
        <th>Remaining</th>
        <th>Updated</th>
        <th>Reset/Unblock</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rateEntries as $entry): ?>
      <tr>
        <td><code><?= rdv_html((string) $entry['scope']) ?></code></td>
        <td><?= (int) $entry['limit'] ?>/<?= (int) ($entry['window_sec'] ?? 0) ?>s</td>
        <td><code><?= rdv_html((string) $entry['ip']) ?></code></td>
        <td>
          <?php if (!empty($entry['is_blocked'])): ?>
            <span class="pill pill-bad">Blocked</span>
          <?php else: ?>
            <span class="pill pill-ok">Active</span>
          <?php endif; ?>
        </td>
        <td><?= (int) $entry['count'] ?> / <?= (int) $entry['limit'] ?></td>
        <td><?= (int) $entry['remaining'] ?></td>
        <td><?= $entry['updated_at'] ? rdv_html(date('Y-m-d H:i:s', (int) $entry['updated_at'])) : 'n/a' ?></td>
        <td><?= $entry['reset_at'] ? rdv_html(date('Y-m-d H:i:s', (int) $entry['reset_at'])) : 'n/a' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php
// View a single principal
elseif ($action === 'view_user'):
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $principal = $id ? rdv_fetch_principal($pdo, $id) : null;

    if (!$principal): ?>
  <p class="error">Principal not found.</p>
<?php else:
    $puri = (string) $principal['uri'];
    ?>
  <hr>
  <h2>Principal</h2>
  <p>
    <strong>URI:</strong> <?= rdv_html($puri) ?><br>
    <strong>Email:</strong> <?= rdv_html((string) ($principal['email'] ?? '')) ?><br>
    <strong>Display Name:</strong> <?= rdv_html((string) ($principal['displayname'] ?? '')) ?>
  </p>

  <h3>Calendars</h3>
  <p class="muted">CalDAV collections for this principal.</p>
  <?php $cals = rdv_fetch_calendars($pdo, $puri); ?>
  <?php if (!$cals): ?>
    <p class="muted">No calendars found.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>URI</th>
          <th>Name</th>
          <th>Components</th>
          <th>Transparency</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cals as $c): ?>
        <tr>
          <td><?= (int) $c['id'] ?></td>
          <td><?= rdv_html((string) $c['uri']) ?></td>
          <td>
            <form class="inline" method="post" action="?action=update_calendar">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="instance_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
              <input type="text" name="displayname" value="<?= rdv_html((string) $c['displayname']) ?>" size="16">
          </td>
          <td>
              <select name="components">
                <?php
                  $comp = strtoupper((string) $c['components']);
                  $opts = [
                      'VEVENT,VTODO' => 'Events + Tasks',
                      'VEVENT'       => 'Events only',
                      'VTODO'        => 'Tasks only',
                  ];
                  foreach ($opts as $val => $label):
                ?>
                  <option value="<?= $val ?>" <?= ($comp === $val ? 'selected' : '') ?>><?= rdv_html($label) ?></option>
                <?php endforeach; ?>
              </select>
          </td>
          <td>
              <?php $trans = (int) $c['transparent'] === 1; ?>
              <label>
                <input type="checkbox" name="transparent" value="1" <?= $trans ? 'checked' : '' ?>>
                <span class="muted">transparent</span>
              </label>
          </td>
          <td>
              <button class="btn small" type="submit">Save</button>
            </form>
            <form class="inline" method="post" action="?action=delete_calendar"
                  onsubmit="return confirm('Delete this calendar (and all its events)?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="instance_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" action="?action=create_calendar">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
    <input type="hidden" name="principal_uri" value="<?= rdv_html($puri) ?>">
    <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
    <p>
      <strong>Create calendar:</strong>
      <input type="text" name="displayname" placeholder="Calendar name">
      <select name="components">
        <option value="VEVENT,VTODO">Events + Tasks</option>
        <option value="VEVENT">Events only</option>
        <option value="VTODO">Tasks only</option>
      </select>
      <button class="btn small" type="submit">Add</button>
    </p>
  </form>

  <h3>Addressbooks</h3>
  <p class="muted">CardDAV addressbooks for this principal.</p>
  <?php $abs = rdv_fetch_addressbooks($pdo, $puri); ?>
  <?php if (!$abs): ?>
    <p class="muted">No addressbooks found.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>URI</th>
          <th>Name</th>
          <th>Description</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($abs as $ab): ?>
        <tr>
          <td><?= (int) $ab['id'] ?></td>
          <td><?= rdv_html((string) $ab['uri']) ?></td>
          <td>
            <!-- EDIT form for addressbook name + description -->
            <form class="inline" method="post" action="?action=update_addressbook">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="addressbook_id" value="<?= (int) $ab['id'] ?>">
              <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
              <input type="text" name="displayname" value="<?= rdv_html((string) $ab['displayname']) ?>" size="16">
          </td>
          <td>
              <input type="text" name="description" value="<?= rdv_html((string) ($ab['description'] ?? '')) ?>" size="24">
          </td>
          <td>
              <button class="btn small" type="submit">Save</button>
            </form>
            <form class="inline" method="post" action="?action=delete_addressbook"
                  onsubmit="return confirm('Delete this addressbook (and all its contacts)?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="addressbook_id" value="<?= (int) $ab['id'] ?>">
              <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" action="?action=create_addressbook">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
    <input type="hidden" name="principal_uri" value="<?= rdv_html($puri) ?>">
    <input type="hidden" name="principal_id" value="<?= (int) $principal['id'] ?>">
    <p>
      <strong>Create addressbook:</strong>
      <input type="text" name="displayname" placeholder="Addressbook name">
      <button class="btn small" type="submit">Add</button>
    </p>
  </form>

  <?php endif; ?>

<?php
// Bookmarks sharing page
elseif (in_array($action, ['sharing_bookmarks', 'bookmarks'], true)): ?>

  <?php
    $bookmarkStats    = $bookmarkService->stats();
    $bookmarkDomains  = $bookmarkService->listDomainSettings();
    $editKey          = strtolower(trim((string) ($_GET['domain'] ?? '')));
    $editingDomain    = null;
    foreach ($bookmarkDomains as $domainRow) {
        $domainName = strtolower((string) ($domainRow['domain'] ?? ''));
        if ($domainName !== '' && $domainName === $editKey) {
            $editingDomain = $domainRow;
            break;
        }
    }
    $alertMessage = '';
    $alertType    = 'success';
    if (!empty($_GET['saved'])) {
        $alertMessage = 'Domain settings saved.';
    } elseif (!empty($_GET['deleted'])) {
        $alertMessage = 'Domain settings removed.';
    } elseif (!empty($_GET['error'])) {
        $alertMessage = (string) $_GET['error'];
        $alertType    = 'error';
    }
    $editingDomainValue = rdv_bookmark_domain_form_value($editingDomain['domain'] ?? null);
  ?>

  <hr>
  <h2>Bookmarks sharing</h2>
  <p class="muted">Keep shared bookmark collections sane: define per-domain quotas, rename the shared folders, and toggle the shared experience entirely.</p>

  <?php if ($alertMessage): ?>
    <div class="alert <?= $alertType === 'error' ? 'error' : 'success' ?>">
      <?= rdv_html($alertMessage) ?>
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Domain</th>
        <th>Private</th>
        <th>Shared</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$bookmarkStats): ?>
      <tr><td colspan="4" class="muted">No bookmarks yet.</td></tr>
    <?php else: ?>
      <?php foreach ($bookmarkStats as $stat): ?>
      <tr>
        <td><?= rdv_bookmark_domain_label($stat['owner_domain'] ?? '') ?></td>
        <td><?= (int) ($stat['private_count'] ?? 0) ?></td>
        <td><?= (int) ($stat['shared_count'] ?? 0) ?></td>
        <td><?= (int) ($stat['total'] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <hr>
  <h3 id="domain-form">Domain sharing rules</h3>
  <p class="muted">Leave domain blank to affect Roundcube users without an @domain.</p>
  <form method="post" action="?action=bookmark_domain_save">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
    <p>
      <label>Domain<br>
        <input type="text" name="domain" value="<?= rdv_html($editingDomainValue) ?>" placeholder="example.com">
      </label>
    </p>
    <p>
      <label>Shared label<br>
        <input type="text" name="shared_label" value="<?= rdv_html($editingDomain['shared_label'] ?? 'Shared Bookmarks') ?>">
      </label>
    </p>
    <p>
      <label>Max private bookmarks (0 = unlimited)<br>
        <input type="text" name="max_private" value="<?= rdv_html((string) ($editingDomain['max_private'] ?? '')) ?>">
      </label>
    </p>
    <p>
      <label>Max shared bookmarks (0 = unlimited)<br>
        <input type="text" name="max_shared" value="<?= rdv_html((string) ($editingDomain['max_shared'] ?? '')) ?>">
      </label>
    </p>
    <p>
      <label>
        <input type="checkbox" name="shared_enabled" value="1" <?= (!isset($editingDomain['shared_enabled']) || (int) $editingDomain['shared_enabled'] === 1) ? 'checked' : '' ?>>
        Shared bookmarks enabled
      </label>
    </p>
    <p>
      <label>Notes<br>
        <textarea name="notes" rows="2" style="width:100%;background:#020617;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;"><?= rdv_html($editingDomain['notes'] ?? '') ?></textarea>
      </label>
    </p>
    <p><button class="btn" type="submit"><?= $editingDomain ? 'Update domain' : 'Add domain' ?></button></p>
  </form>

  <?php if ($bookmarkDomains): ?>
    <table>
      <thead>
        <tr>
          <th>Domain</th>
          <th>Shared</th>
          <th>Private max</th>
          <th>Shared max</th>
          <th>Label</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bookmarkDomains as $domainRow): ?>
        <tr>
          <td><?= rdv_bookmark_domain_label($domainRow['domain'] ?? '') ?></td>
          <td>
            <?php if (!empty($domainRow['shared_enabled'])): ?>
              <span class="pill pill-ok">Enabled</span>
            <?php else: ?>
              <span class="pill pill-bad">Disabled</span>
            <?php endif; ?>
          </td>
          <td><?= $domainRow['max_private'] ? (int) $domainRow['max_private'] : '∞' ?></td>
          <td><?= $domainRow['max_shared'] ? (int) $domainRow['max_shared'] : '∞' ?></td>
          <td><?= rdv_html($domainRow['shared_label'] ?? '') ?></td>
          <td>
            <a class="btn small" href="?action=sharing_bookmarks&amp;domain=<?= rawurlencode((string) ($domainRow['domain'] ?? '')) ?>#domain-form">Edit</a>
            <form class="inline" method="post" action="?action=bookmark_domain_delete" onsubmit="return confirm('Delete overrides for this domain?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="domain" value="<?= rdv_html($domainRow['domain'] ?? '') ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">No domain overrides defined yet.</p>
  <?php endif; ?>

<?php
// Sharing / global collections
elseif ($action === 'sharing'): ?>
  <?php
    $collections         = rdv_fetch_global_collections($pdo);
    $calendarCollections = array_filter($collections, function (array $c): bool {
        return ($c['type'] ?? '') === 'calendar';
    });
    $addressCollections  = array_filter($collections, function (array $c): bool {
        return ($c['type'] ?? '') === 'addressbook';
    });
    $bookmarkStats       = $bookmarkService->stats();
    $fileSettings        = rdv_get_file_share_settings();
  ?>
  <hr>
  <h2>Sharing consolidation</h2>
  <p class="muted">
    Configure every sharing surface from one place. Choose the entity you need to fine‑tune
    and you’ll land on a focused page with presets, autofill helpers, and the canonical
    <code>rounddav_global_collections</code> + <code>rounddav_global_permissions</code> data.
  </p>

  <div class="share-grid">
    <a class="share-card" href="?action=sharing_calendar">
      <h3>Calendar sharing</h3>
      <p>Create per‑component templates (VEVENT / VTODO), auto‑assign to new principals,
         and review every CalDAV permission rule.</p>
      <strong><?= count($calendarCollections) ?> shared calendars</strong>
    </a>
    <a class="share-card" href="?action=sharing_addressbook">
      <h3>Addressbook sharing</h3>
      <p>Manage shared addressbooks with sync direction selectors and auto-complete notes
         so admins can safely expose contact datasets.</p>
      <strong><?= count($addressCollections) ?> shared addressbooks</strong>
    </a>
    <a class="share-card" href="?action=sharing_files">
      <h3>Files sharing</h3>
      <p>Define defaults for expiration, access level, and notification behavior for the
         built-in storage UI.</p>
      <strong><?= rdv_html(strtoupper($fileSettings['default_access'] ?? 'READ')) ?> default access</strong>
    </a>
    <a class="share-card" href="?action=sharing_bookmarks">
      <h3>Bookmarks sharing</h3>
      <p>Per-domain quotas, friendly labels, and toggleable shared bookmark experiences.</p>
      <strong><?= $bookmarkStats ? count($bookmarkStats) : 0 ?> tracked domains</strong>
    </a>
  </div>

  <div class="share-config">
    <h3>At a glance</h3>
    <?php if (!$collections): ?>
      <p class="muted">No shared calendars/addressbooks yet. Jump into a card above to add your first.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>URI</th>
            <th>Name</th>
            <th>Rules</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($collections as $col): ?>
          <tr>
            <td><?= (int) $col['id'] ?></td>
            <td><?= rdv_html((string) $col['type']) ?></td>
            <td><code><?= rdv_html((string) $col['uri']) ?></code></td>
            <td><?= rdv_html((string) ($col['displayname'] ?? '')) ?></td>
            <td><?= rdv_count_global_permissions($pdo, (int) $col['id']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php
// Calendar sharing landing
elseif ($action === 'sharing_calendar'): ?>
  <?php
    $collections = array_values(array_filter(
        rdv_fetch_global_collections($pdo),
        function (array $c): bool {
            return ($c['type'] ?? '') === 'calendar';
        }
    ));
    $alertMessage = '';
    if (!empty($_GET['created'])) {
        $alertMessage = 'Calendar share created.';
    } elseif (!empty($_GET['updated'])) {
        $alertMessage = 'Calendar settings updated.';
    }
  ?>
  <hr>
  <h2>Calendar sharing</h2>
  <p class="muted">
    Build global CalDAV collections and fine tune who sees VEVENT/VTODO data. Settings
    persist in <code>rounddav_global_collections</code> plus supplemental metadata in
    <code><?= rdv_html(basename(RDV_SHARING_CONFIG_FILE)) ?></code>.
  </p>
  <?php if ($alertMessage): ?>
    <div class="alert success"><?= rdv_html($alertMessage) ?></div>
  <?php endif; ?>

  <div class="form-grid">
    <div class="form-card stacked-form">
      <h3>Create share</h3>
      <form method="post" action="?action=create_global_collection">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <input type="hidden" name="type" value="calendar">
        <input type="hidden" name="redirect_action" value="sharing_calendar">
        <label>URI<br>
          <input type="text" name="uri" placeholder="/calendars/gene/shared-team">
        </label>
        <label>Display name<br>
          <input type="text" name="displayname" placeholder="Team calendar">
        </label>
        <label>Description<br>
          <input type="text" name="description" placeholder="Visible in clients as...">
        </label>
        <label>Components<br>
          <select name="components">
            <option value="VEVENT,VTODO">VEVENT + VTODO</option>
            <option value="VEVENT">VEVENT only</option>
            <option value="VTODO">VTODO only</option>
          </select>
        </label>
        <label>Default access<br>
          <select name="default_access">
            <option value="ro">Read-only</option>
            <option value="rw">Read &amp; write</option>
          </select>
        </label>
        <label>Timezone<br>
          <input list="calendar-tz" type="text" name="timezone" value="UTC">
        </label>
        <label>
          <input type="checkbox" name="auto_assign" value="1">
          Auto-assign to every new principal (helpful for org-wide calendars)
        </label>
        <label>Autofill note<br>
          <textarea name="autofill_note" placeholder="Shown to admins configuring a user"></textarea>
        </label>
        <p><button class="btn small" type="submit">Create</button></p>
      </form>
    </div>
    <div class="form-card stacked-form">
      <h3>Tips</h3>
      <p class="muted">
        Use short URIs with user placeholders (e.g. <code>/calendars/shared/company-holidays</code>).
        Components restrict which objects can be stored. Auto-assign ensures new accounts
        immediately inherit important calendars.
      </p>
      <p class="muted">
        You can mirror user-specific text in the autofill note with tokens like
        <code>{{username}}</code> or <code>{{email}}</code>; these are simply stored as hints
        for provisioning flows.
      </p>
    </div>
  </div>

  <datalist id="calendar-tz">
    <option value="UTC">
    <option value="America/New_York">
    <option value="Europe/Berlin">
    <option value="Asia/Tokyo">
    <option value="Australia/Sydney">
  </datalist>

  <?php if (!$collections): ?>
    <p class="muted">No calendar shares yet.</p>
  <?php else: ?>
    <?php foreach ($collections as $col): ?>
      <?php
        $settings = rdv_get_calendar_share_settings((int) $col['id']);
        $perms    = rdv_fetch_global_permissions($pdo, (int) $col['id']);
      ?>
      <div class="share-config">
        <h3><?= rdv_html((string) ($col['displayname'] ?? $col['uri'])) ?>
          <span class="pill">ID <?= (int) $col['id'] ?></span>
        </h3>
        <p class="muted">
          <strong>URI:</strong> <code><?= rdv_html((string) $col['uri']) ?></code> ·
          <strong>Timezone:</strong> <?= rdv_html($settings['timezone'] ?? 'UTC') ?> ·
          <strong>Rules:</strong> <?= rdv_count_global_permissions($pdo, (int) $col['id']) ?>
        </p>
        <form class="stacked-form" method="post" action="?action=update_calendar_share">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <label>Components<br>
            <select name="components">
              <option value="VEVENT,VTODO" <?= ($settings['components'] ?? '') === 'VEVENT,VTODO' ? 'selected' : '' ?>>VEVENT + VTODO</option>
              <option value="VEVENT" <?= ($settings['components'] ?? '') === 'VEVENT' ? 'selected' : '' ?>>VEVENT only</option>
              <option value="VTODO" <?= ($settings['components'] ?? '') === 'VTODO' ? 'selected' : '' ?>>VTODO only</option>
            </select>
          </label>
          <label>Default access<br>
            <select name="default_access">
              <option value="ro" <?= ($settings['default_access'] ?? '') === 'ro' ? 'selected' : '' ?>>Read-only</option>
              <option value="rw" <?= ($settings['default_access'] ?? '') === 'rw' ? 'selected' : '' ?>>Read &amp; write</option>
            </select>
          </label>
          <label>Timezone<br>
            <input type="text" name="timezone" value="<?= rdv_html((string) ($settings['timezone'] ?? 'UTC')) ?>">
          </label>
          <label>
            <input type="checkbox" name="auto_assign" value="1" <?= !empty($settings['auto_assign']) ? 'checked' : '' ?>>
            Auto-assign to every principal
          </label>
          <label>Autofill note<br>
            <textarea name="autofill_note"><?= rdv_html((string) ($settings['autofill_note'] ?? '')) ?></textarea>
          </label>
          <p><button class="btn small" type="submit">Save settings</button></p>
        </form>
        <form class="inline" method="post" action="?action=delete_global_collection" onsubmit="return confirm('Delete this shared calendar?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <input type="hidden" name="redirect_action" value="sharing_calendar">
          <button class="btn small danger" type="submit">Delete</button>
        </form>

        <h4>Permissions</h4>
        <?php if (!$perms): ?>
          <p class="muted">No principals assigned.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Principal</th>
                <th>Mode</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($perms as $perm): ?>
              <tr>
                <td>
                  <?php if ((string) $perm['principal_uri'] === '*'): ?>
                    <span class="pill pill-ok">ALL USERS</span>
                  <?php else: ?>
                    <code><?= rdv_html((string) $perm['principal_uri']) ?></code>
                  <?php endif; ?>
                </td>
                <td><?= !empty($perm['read_only']) ? 'read-only' : 'read/write' ?></td>
                <td>
                  <form class="inline" method="post" action="?action=delete_global_permission" onsubmit="return confirm('Remove this permission?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
                    <input type="hidden" name="permission_id" value="<?= (int) $perm['id'] ?>">
                    <input type="hidden" name="redirect_action" value="sharing_calendar">
                    <button class="btn small danger" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <form method="post" action="?action=add_global_permission">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <input type="hidden" name="redirect_action" value="sharing_calendar">
          <p>
            <strong>Grant access:</strong>
            <input type="text" name="principal_uri" placeholder="principals/users/jane">
            <label style="margin-left:8px;">
              <input type="checkbox" name="all_users" value="1">
              All users
            </label>
            <label style="margin-left:8px;">
              <input type="checkbox" name="read_only" value="1" <?= ($settings['default_access'] ?? '') !== 'rw' ? 'checked' : '' ?>>
              Read-only
            </label>
            <button class="btn small" type="submit">Add</button>
          </p>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php
// Addressbook sharing landing
elseif ($action === 'sharing_addressbook'): ?>
  <?php
    $collections = array_values(array_filter(
        rdv_fetch_global_collections($pdo),
        function (array $c): bool {
            return ($c['type'] ?? '') === 'addressbook';
        }
    ));
    $alertMessage = '';
    if (!empty($_GET['created'])) {
        $alertMessage = 'Addressbook share created.';
    } elseif (!empty($_GET['updated'])) {
        $alertMessage = 'Addressbook settings updated.';
    }
  ?>
  <hr>
  <h2>Addressbook sharing</h2>
  <p class="muted">
    Create shared CardDAV addressbooks with explicit sync direction controls and optional
    hints for administrators assigning access to principals.
  </p>
  <?php if ($alertMessage): ?>
    <div class="alert success"><?= rdv_html($alertMessage) ?></div>
  <?php endif; ?>

  <div class="form-grid">
    <div class="form-card stacked-form">
      <h3>Create share</h3>
      <form method="post" action="?action=create_global_collection">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <input type="hidden" name="type" value="addressbook">
        <input type="hidden" name="redirect_action" value="sharing_addressbook">
        <label>URI<br>
          <input type="text" name="uri" placeholder="/addressbooks/company/shared">
        </label>
        <label>Display name<br>
          <input type="text" name="displayname" placeholder="Company directory">
        </label>
        <label>Description<br>
          <input type="text" name="description" placeholder="Optional summary">
        </label>
        <label>Sync mode<br>
          <select name="sync_mode">
            <option value="ro">Read-only</option>
            <option value="rw">Read &amp; write</option>
          </select>
        </label>
        <label>
          <input type="checkbox" name="auto_assign" value="1">
          Auto-assign to new principals
        </label>
        <label>
          <input type="checkbox" name="auto_complete" value="1" checked>
          Show in Roundcube auto-complete
        </label>
        <label>Autofill note<br>
          <textarea name="autofill_note" placeholder="e.g. Contains HR contacts only."></textarea>
        </label>
        <p><button class="btn small" type="submit">Create</button></p>
      </form>
    </div>
    <div class="form-card">
      <h3>Guidance</h3>
      <p class="muted">
        Addressbook URIs often live under <code>/addressbooks/shared</code>. Use the notes
        field to remind admins what data is included or which groups should be present.
      </p>
      <p class="muted">
        Auto-complete is useful for global directories, but you can uncheck it for sensitive
        collections (executives, finance, etc.).
      </p>
    </div>
  </div>

  <?php if (!$collections): ?>
    <p class="muted">No addressbook shares yet.</p>
  <?php else: ?>
    <?php foreach ($collections as $col): ?>
      <?php
        $settings = rdv_get_addressbook_share_settings((int) $col['id']);
        $perms    = rdv_fetch_global_permissions($pdo, (int) $col['id']);
      ?>
      <div class="share-config">
        <h3><?= rdv_html((string) ($col['displayname'] ?? $col['uri'])) ?>
          <span class="pill">ID <?= (int) $col['id'] ?></span>
        </h3>
        <p class="muted">
          <strong>URI:</strong> <code><?= rdv_html((string) $col['uri']) ?></code> ·
          <strong>Rules:</strong> <?= rdv_count_global_permissions($pdo, (int) $col['id']) ?>
        </p>
        <form class="stacked-form" method="post" action="?action=update_addressbook_share">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <label>Sync mode<br>
            <select name="sync_mode">
              <option value="ro" <?= ($settings['sync_mode'] ?? '') === 'ro' ? 'selected' : '' ?>>Read-only</option>
              <option value="rw" <?= ($settings['sync_mode'] ?? '') === 'rw' ? 'selected' : '' ?>>Read &amp; write</option>
            </select>
          </label>
          <label>
            <input type="checkbox" name="auto_assign" value="1" <?= !empty($settings['auto_assign']) ? 'checked' : '' ?>>
            Auto-assign to principals
          </label>
          <label>
            <input type="checkbox" name="auto_complete" value="1" <?= (!isset($settings['auto_complete']) || !empty($settings['auto_complete'])) ? 'checked' : '' ?>>
            Show in Roundcube auto-complete
          </label>
          <label>Autofill note<br>
            <textarea name="autofill_note"><?= rdv_html((string) ($settings['autofill_note'] ?? '')) ?></textarea>
          </label>
          <p><button class="btn small" type="submit">Save settings</button></p>
        </form>
        <form class="inline" method="post" action="?action=delete_global_collection" onsubmit="return confirm('Delete this shared addressbook?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <input type="hidden" name="redirect_action" value="sharing_addressbook">
          <button class="btn small danger" type="submit">Delete</button>
        </form>

        <h4>Permissions</h4>
        <?php if (!$perms): ?>
          <p class="muted">No principals assigned.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Principal</th>
                <th>Mode</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($perms as $perm): ?>
              <tr>
                <td>
                  <?php if ((string) $perm['principal_uri'] === '*'): ?>
                    <span class="pill pill-ok">ALL USERS</span>
                  <?php else: ?>
                    <code><?= rdv_html((string) $perm['principal_uri']) ?></code>
                  <?php endif; ?>
                </td>
                <td><?= !empty($perm['read_only']) ? 'read-only' : 'read/write' ?></td>
                <td>
                  <form class="inline" method="post" action="?action=delete_global_permission" onsubmit="return confirm('Remove this permission?');">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
                    <input type="hidden" name="permission_id" value="<?= (int) $perm['id'] ?>">
                    <input type="hidden" name="redirect_action" value="sharing_addressbook">
                    <button class="btn small danger" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <form method="post" action="?action=add_global_permission">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
          <input type="hidden" name="collection_id" value="<?= (int) $col['id'] ?>">
          <input type="hidden" name="redirect_action" value="sharing_addressbook">
          <p>
            <strong>Grant access:</strong>
            <input type="text" name="principal_uri" placeholder="principals/groups/support">
            <label style="margin-left:8px;">
              <input type="checkbox" name="all_users" value="1">
              All users
            </label>
            <label style="margin-left:8px;">
              <input type="checkbox" name="read_only" value="1" <?= ($settings['sync_mode'] ?? '') !== 'rw' ? 'checked' : '' ?>>
              Read-only
            </label>
            <button class="btn small" type="submit">Add</button>
          </p>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php
// Files sharing defaults
elseif ($action === 'sharing_files'): ?>
  <?php
    $fileSettings = rdv_get_file_share_settings();
    $alertMessage = '';
    if (!empty($_GET['saved'])) {
        $alertMessage = 'File sharing settings saved.';
    } elseif (!empty($error)) {
        $alertMessage = $error;
    }
  ?>
  <hr>
  <h2>Files sharing</h2>
  <p class="muted">
    Tune the web file UI defaults—control how public links behave, whether admins get
    notified, and what template is used when auto-generating share invitations.
  </p>
  <?php if ($alertMessage): ?>
    <div class="alert <?= !empty($_GET['saved']) ? 'success' : 'error' ?>"><?= rdv_html($alertMessage) ?></div>
  <?php endif; ?>

  <div class="share-config">
    <form class="stacked-form" method="post" action="?action=update_file_sharing">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
      <label>Default permission<br>
        <select name="default_access">
          <option value="read" <?= ($fileSettings['default_access'] ?? '') === 'read' ? 'selected' : '' ?>>Read only</option>
          <option value="write" <?= ($fileSettings['default_access'] ?? '') === 'write' ? 'selected' : '' ?>>Read &amp; upload</option>
        </select>
      </label>
      <label>Default link expiration (days, 0 = never)<br>
        <input type="number" min="0" name="default_expiry_days" value="<?= (int) ($fileSettings['default_expiry_days'] ?? 30) ?>">
      </label>
      <label>
        <input type="checkbox" name="allow_external" value="1" <?= !empty($fileSettings['allow_external']) ? 'checked' : '' ?>>
        Allow sharing links with external recipients
      </label>
      <label>
        <input type="checkbox" name="notify_owner" value="1" <?= !empty($fileSettings['notify_owner']) ? 'checked' : '' ?>>
        Email owners when someone opens a share link
      </label>
      <label>
        <input type="checkbox" name="auto_fill_from_user" value="1" <?= !empty($fileSettings['auto_fill_from_user']) ? 'checked' : '' ?>>
        Autofill text areas with the selected user's information
      </label>
      <label>Invite template<br>
        <textarea name="template" rows="4"><?= rdv_html((string) ($fileSettings['template'] ?? '')) ?></textarea>
      </label>
      <p><button class="btn small" type="submit">Save changes</button></p>
    </form>
  </div>

  <div class="share-config">
    <h3>Template preview</h3>
    <?php
      $preview = $fileSettings;
      $previewLine = strtr($preview['template'] ?? '', [
          '{{user}}'   => 'jane@domain',
          '{{path}}'   => '/shared/designs',
          '{{expires}}'=> date('Y-m-d', strtotime('+' . ((int) ($preview['default_expiry_days'] ?? 30)) . ' days')),
      ]);
    ?>
    <pre style="white-space:pre-wrap;line-height:1.5;background:#020617;border-radius:10px;padding:12px;"><?= rdv_html($previewLine) ?></pre>
  </div>

<?php
// Config view
elseif ($action === 'config'): ?>

  <?php
    $configSaved = !empty($_GET['saved']);
    $configImported = !empty($_GET['imported']);
    $configRestored = trim((string) ($_GET['restored'] ?? ''));
    $backupCreated = trim((string) ($_GET['backup_created'] ?? ''));
    $backupPolicySaved = !empty($_GET['backup_policy_saved']);
    $backupPruned = max(0, (int) ($_GET['backup_pruned'] ?? 0));
    $backupPruneFailed = max(0, (int) ($_GET['backup_prune_failed'] ?? 0));
    $importPreview = $_SESSION['rdv_config_import_preview'] ?? null;
    if (!is_array($importPreview)) {
        $importPreview = null;
    }
    $backupRetention = rdv_backup_retention_settings($config);
    $backupItems = rdv_list_config_backups(RDV_CONFIG_FILE, 30);
    $backupSummary = [
        'count' => 0,
        'total_size' => 0,
        'newest_mtime' => 0,
        'oldest_mtime' => 0,
    ];
    if ($backupItems) {
        $backupSummary['count'] = count($backupItems);
        $mtimes = [];
        foreach ($backupItems as $item) {
            $size = (int) ($item['size'] ?? 0);
            $mtime = (int) ($item['mtime'] ?? 0);
            if ($size > 0) {
                $backupSummary['total_size'] += $size;
            }
            if ($mtime > 0) {
                $mtimes[] = $mtime;
            }
        }
        if ($mtimes) {
            $backupSummary['newest_mtime'] = max($mtimes);
            $backupSummary['oldest_mtime'] = min($mtimes);
        }
    }
    $headerDiag = rdv_security_headers_diagnostics($config);
    $expectedHeaders = rdv_expected_security_headers($config);
  ?>
  <hr>
<h2>Server configuration</h2>
<p class="muted">
  Update <code><?= rdv_html(basename(RDV_CONFIG_FILE)) ?></code> directly from the browser.
  We only expose security-relevant knobs here; database credentials remain read-only.
</p>
<?php if ($configSaved): ?>
  <div class="alert success">Configuration saved.</div>
  <?php if ($configImported): ?>
    <div class="alert success">Config snapshot imported and applied.</div>
  <?php endif; ?>
  <?php if ($configRestored !== ''): ?>
    <div class="alert success">Config restored from backup: <code><?= rdv_html($configRestored) ?></code></div>
  <?php endif; ?>
<?php elseif (!empty($error)): ?>
  <div class="alert error"><?= rdv_html($error) ?></div>
<?php endif; ?>
<?php if ($backupCreated !== ''): ?>
  <div class="alert success">Backup created: <code><?= rdv_html($backupCreated) ?></code></div>
<?php endif; ?>
<?php if ($backupPolicySaved): ?>
  <div class="alert success">Backup retention policy saved.</div>
<?php endif; ?>
<?php if (isset($_GET['backup_pruned'])): ?>
  <div class="alert success">Backup prune complete. Removed <?= $backupPruned ?> backup(s)<?= $backupPruneFailed > 0 ? '; failed to remove ' . $backupPruneFailed . '.' : '.' ?></div>
<?php endif; ?>

<script>
(function() {
  function initSecurityHeaderTools() {
    var crossBtn = document.getElementById('apply-cross-origin-profile');
    var sameBtn = document.getElementById('apply-same-origin-profile');
    var verifyBtn = document.getElementById('verify-security-headers');
    var statusEl = document.getElementById('security-profile-status');
    var verifyResultsEl = document.getElementById('security-headers-verify-results');

    if (!crossBtn || !sameBtn || !verifyBtn) {
      return;
    }

  var profiles = {
    cross: <?= json_encode($headerDiag['profile_cross_origin_fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    same: <?= json_encode($headerDiag['profile_same_origin_fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  };
  var expectedHeaders = <?= json_encode($expectedHeaders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var relevantHeaders = [
    'x-content-type-options',
    'referrer-policy',
    'permissions-policy',
    'x-frame-options',
    'content-security-policy'
  ];
  var targets = [
    { label: 'Admin UI', url: '?action=config' },
    { label: 'Files UI', url: '../files/index.php?action=login' }
  ];

  function setStatus(text) {
    if (!statusEl) {
      return;
    }
    statusEl.style.display = '';
    statusEl.textContent = text;
  }

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function applyProfile(profile, label) {
    Object.keys(profile).forEach(function(name) {
      var el = document.querySelector('[name="' + name + '"]');
      if (!el) {
        return;
      }

      if (el.type === 'checkbox') {
        el.checked = !!profile[name];
      } else {
        el.value = profile[name];
      }
    });

    setStatus(label + ' applied. Review values and click "Save configuration" to persist.');
  }

  crossBtn.addEventListener('click', function() {
    applyProfile(profiles.cross, 'Cross-origin embed profile');
  });

  sameBtn.addEventListener('click', function() {
    applyProfile(profiles.same, 'Same-origin locked profile');
  });

  async function verifyOne(target) {
    var res = await fetch(target.url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    });

    var actual = {};
    relevantHeaders.forEach(function(name) {
      var value = res.headers.get(name);
      actual[name] = value === null ? '' : value;
    });

    return {
      label: target.label,
      url: target.url,
      status: res.status,
      actual: actual
    };
  }

  function renderVerification(results, errorText) {
    if (!verifyResultsEl) {
      return;
    }

    verifyResultsEl.style.display = '';

    if (errorText) {
      verifyResultsEl.className = 'alert error';
      verifyResultsEl.textContent = errorText;
      return;
    }

    var html = '';
    results.forEach(function(result) {
      html += '<h4>' + esc(result.label) + ' <span class="pill">HTTP ' + esc(result.status) + '</span></h4>';
      html += '<table><thead><tr><th>Header</th><th>Expected</th><th>Actual</th><th>Status</th></tr></thead><tbody>';

      relevantHeaders.forEach(function(name) {
        var expected = expectedHeaders[name] || '';
        var actual = result.actual[name] || '';
        var status = 'OK';
        if (expected === '' && actual !== '') {
          status = 'Extra';
        } else if (expected !== '' && actual === '') {
          status = 'Missing';
        } else if (expected !== '' && actual !== expected) {
          status = 'Mismatch';
        }

        html += '<tr>'
          + '<td><code>' + esc(name) + '</code></td>'
          + '<td>' + (expected === '' ? '<span class="muted">(not set)</span>' : '<code>' + esc(expected) + '</code>') + '</td>'
          + '<td>' + (actual === '' ? '<span class="muted">(not set)</span>' : '<code>' + esc(actual) + '</code>') + '</td>'
          + '<td>' + esc(status) + '</td>'
          + '</tr>';
      });

      html += '</tbody></table>';
    });

    verifyResultsEl.className = 'share-config';
    verifyResultsEl.innerHTML = html;
  }

    verifyBtn.addEventListener('click', async function() {
      if (verifyResultsEl) {
        verifyResultsEl.style.display = '';
        verifyResultsEl.className = 'muted';
        verifyResultsEl.textContent = 'Verifying current response headers...';
      }

      try {
        var results = await Promise.all(targets.map(verifyOne));
        renderVerification(results, '');
      } catch (err) {
        renderVerification([], 'Header verification failed: ' + (err && err.message ? err.message : String(err)));
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSecurityHeaderTools);
  } else {
    initSecurityHeaderTools();
  }
})();
</script>

<form method="post" action="?action=update_config">
  <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
  <div class="settings-grid">
    <div class="form-card stacked-form">
      <h3>General</h3>
      <label>
        <input type="checkbox" name="debug" value="1" <?= !empty($config['debug']) ? 'checked' : '' ?>>
        Enable debug mode
      </label>
      <label>Base URI<br>
        <input type="text" name="base_uri" value="<?= rdv_html((string) ($config['base_uri'] ?? '')) ?>" placeholder="/rounddav/">
      </label>
      <label>Locale<br>
        <input type="text" name="locale_default" value="<?= rdv_html((string) ($config['locale']['default'] ?? 'en_US')) ?>">
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Logging</h3>
      <label>
        <input type="checkbox" name="log_enabled" value="1" <?= !empty($config['log']['enabled']) ? 'checked' : '' ?>>
        Enable request logging
      </label>
      <label>Log file<br>
        <input type="text" name="log_file" value="<?= rdv_html((string) ($config['log']['file'] ?? '')) ?>">
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Files</h3>
      <label>
        <input type="checkbox" name="files_enabled" value="1" <?= !empty($config['files']['enabled']) ? 'checked' : '' ?>>
        Enable Files UI
      </label>
      <label>
        <input type="checkbox" name="files_allow_public_links" value="1" <?= !empty($config['files']['allow_public_links']) ? 'checked' : '' ?>>
        Allow public share links
      </label>
      <label>Files root<br>
        <input type="text" name="files_root" value="<?= rdv_html((string) ($config['files']['root'] ?? '')) ?>">
      </label>
      <label>Default quota (MB)<br>
        <input type="number" min="0" name="files_default_quota" value="<?= (int) ($config['files']['default_quota_mb'] ?? 0) ?>">
      </label>
      <label>Shared files write policy<br>
        <select name="files_shared_write_policy">
          <option value="admins_only" <?= (($config['files']['shared_write_policy'] ?? 'admins_only') === 'admins_only') ? 'selected' : '' ?>>Admins only</option>
          <option value="authenticated_users" <?= (($config['files']['shared_write_policy'] ?? '') === 'authenticated_users') ? 'selected' : '' ?>>All authenticated users</option>
        </select>
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Security / Rate limiting</h3>
      <label>
        <input type="checkbox" name="rate_limit_enabled" value="1" <?= !empty($config['security']['rate_limit']['enabled']) ? 'checked' : '' ?>>
        Enable rate limiting
      </label>
      <label>Max requests per window<br>
        <input type="number" min="1" name="rate_limit_max" value="<?= (int) ($config['security']['rate_limit']['max_requests'] ?? 100) ?>">
      </label>
      <label>Window (seconds)<br>
        <input type="number" min="1" name="rate_limit_window" value="<?= (int) ($config['security']['rate_limit']['window_sec'] ?? 300) ?>">
      </label>
      <label>Block duration (minutes)<br>
        <input type="number" min="1" name="rate_limit_block" value="<?= (int) ($config['security']['rate_limit']['block_minutes'] ?? 30) ?>">
      </label>
      <label>
        <input type="checkbox" name="trust_proxy_headers" value="1" <?= !empty($config['security']['trust_proxy_headers']) ? 'checked' : '' ?>>
        Trust <code>X-Forwarded-Host</code> for origin checks (reverse proxy mode)
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Provisioning</h3>
      <label>Base URL<br>
        <input type="text" name="provision_baseurl" value="<?= rdv_html((string) ($config['provision']['baseurl'] ?? '')) ?>">
      </label>
      <label>Principal prefix<br>
        <input type="text" name="provision_principal_prefix" value="<?= rdv_html((string) ($config['provision']['principal_prefix'] ?? 'principals')) ?>">
      </label>
      <label>Provision API shared secret<br>
        <input type="text" name="provision_shared_secret" value="<?= rdv_html((string) ($config['provision']['shared_secret'] ?? '')) ?>">
        <span class="hint">Must match <code>rounddav_api_token</code> in the Roundcube provision plugin.</span>
      </label>
      <label>
        <input type="checkbox" name="generate_provision_secret" value="1">
        Generate a new provisioning secret on save
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>SSO</h3>
      <label>
        <input type="checkbox" name="sso_enabled" value="1" <?= !empty($config['sso']['enabled']) ? 'checked' : '' ?>>
        Enable SSO gateway
      </label>
      <label>Shared secret<br>
        <input type="text" name="sso_secret" value="<?= rdv_html((string) ($config['sso']['secret'] ?? '')) ?>">
        <span class="hint">Must match <code>rounddav_sso_secret</code> in the Roundcube plugin.</span>
      </label>
      <label>Session TTL (seconds)<br>
        <input type="number" min="60" name="sso_ttl" value="<?= (int) ($config['sso']['ttl'] ?? 600) ?>">
      </label>
      <label>
        <input type="checkbox" name="generate_sso_secret" value="1">
        Generate a new SSO secret on save
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Bookmarks API</h3>
      <label>Bookmark shared secret<br>
        <input type="text" name="bookmarks_shared_secret" value="<?= rdv_html((string) ($config['bookmarks']['shared_secret'] ?? '')) ?>">
        <span class="hint">Leave empty to disable dedicated bookmark token and fall back to provisioning token.</span>
      </label>
      <label>
        <input type="checkbox" name="generate_bookmark_secret" value="1">
        Generate a new bookmark API secret on save
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Endpoints</h3>
      <label>
        <input type="checkbox" name="endpoint_enable_testdav" value="1" <?= !empty($config['endpoints']['enable_testdav']) ? 'checked' : '' ?>>
        Enable diagnostic endpoint <code>public/testdav.php</code>
      </label>
    </div>
    <div class="form-card stacked-form">
      <h3>Admin</h3>
      <label>Username<br>
        <input type="text" name="admin_username" value="<?= rdv_html((string) ($config['admin']['username'] ?? '')) ?>">
      </label>
      <label>Contact email<br>
        <input type="email" name="admin_email" value="<?= rdv_html((string) ($config['admin']['email'] ?? '')) ?>">
      </label>
      <label>New password (optional)<br>
        <input type="password" name="admin_password" placeholder="Leave blank to keep current password">
      </label>
    </div>
    <div class="form-card stacked-form full-width">
      <h3>Security Headers</h3>
      <div class="security-headers-columns">
        <div class="security-headers-column">
          <label>
            <input type="checkbox" name="headers_enabled" value="1" <?= !array_key_exists('enabled', (array) ($config['security']['headers'] ?? [])) || !empty($config['security']['headers']['enabled']) ? 'checked' : '' ?>>
            Enable response security headers on Admin/Files pages
          </label>
          <label>
            <input type="checkbox" name="headers_nosniff" value="1" <?= !array_key_exists('x_content_type_options', (array) ($config['security']['headers'] ?? [])) || !empty($config['security']['headers']['x_content_type_options']) ? 'checked' : '' ?>>
            Send <code>X-Content-Type-Options: nosniff</code>
          </label>
          <label>Referrer-Policy<br>
            <input type="text" name="headers_referrer_policy" value="<?= rdv_html((string) ($config['security']['headers']['referrer_policy'] ?? 'strict-origin-when-cross-origin')) ?>">
          </label>
        </div>
        <div class="security-headers-column">
          <label>Permissions-Policy<br>
            <input type="text" name="headers_permissions_policy" value="<?= rdv_html((string) ($config['security']['headers']['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')) ?>">
          </label>
          <label>X-Frame-Options<br>
            <?php $xfoValue = strtoupper((string) ($config['security']['headers']['x_frame_options'] ?? '')); ?>
            <select name="headers_x_frame_options">
              <option value="" <?= $xfoValue === '' ? 'selected' : '' ?>>Disabled (allow embedding)</option>
              <option value="SAMEORIGIN" <?= $xfoValue === 'SAMEORIGIN' ? 'selected' : '' ?>>SAMEORIGIN</option>
              <option value="DENY" <?= $xfoValue === 'DENY' ? 'selected' : '' ?>>DENY</option>
            </select>
          </label>
          <label>
            <input type="checkbox" name="headers_csp_enabled" value="1" <?= !empty($config['security']['headers']['csp']['enabled']) ? 'checked' : '' ?>>
            Enable Content-Security-Policy
          </label>
        </div>
        <div class="security-headers-column">
          <label>CSP policy override (optional)<br>
            <textarea name="headers_csp_policy" rows="4" style="width:100%;background:#020617;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;"><?= rdv_html((string) ($config['security']['headers']['csp']['policy'] ?? '')) ?></textarea>
            <span class="hint">Leave blank to auto-generate a basic policy using frame-ancestors below.</span>
          </label>
          <label>CSP frame-ancestors (one per line or comma-separated)<br>
            <textarea name="headers_csp_frame_ancestors" rows="3" style="width:100%;background:#020617;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;"><?= rdv_html(is_array($config['security']['headers']['csp']['frame_ancestors'] ?? null) ? implode("\n", $config['security']['headers']['csp']['frame_ancestors']) : "'self'") ?></textarea>
            <span class="hint">For cross-origin Roundcube embedding, keep X-Frame-Options disabled and include your Roundcube origin (e.g. <code>https://mail.example.com</code>).</span>
          </label>
        </div>
      </div>
    </div>
  </div>

  <p><button class="btn" type="submit">Save configuration</button></p>
</form>
<p class="muted">Config file: <code><?= rdv_html(RDV_CONFIG_FILE) ?></code>. Rotating provision/SSO secrets requires updating Roundcube plugin settings.</p>

<hr>
<div class="share-config">
  <h3>Config Snapshot</h3>
  <p class="muted">
    Export a JSON snapshot of the current configuration, then preview and apply imports with a diff.
    A timestamped backup of <code>config.php</code> is created automatically before writes/imports.
  </p>

  <div class="settings-grid">
    <div class="form-card stacked-form">
      <h4>Export</h4>
      <form method="post" action="?action=export_config_snapshot">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <label>
          <input type="checkbox" name="include_secrets" value="1">
          Include secrets in snapshot export
        </label>
        <p><button class="btn small" type="submit">Download snapshot</button></p>
      </form>
      <hr>
      <form method="post" action="?action=create_config_backup">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <p><button class="btn small" type="submit">Create backup now</button></p>
      </form>
      <hr>
      <h4>Retention</h4>
      <form method="post" action="?action=update_backup_retention">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <label>Keep newest backups<br>
          <input type="number" min="1" max="1000" name="backup_keep_last" value="<?= (int) ($backupRetention['keep_last'] ?? 30) ?>">
        </label>
        <label>Delete backups older than days (0 = disable)<br>
          <input type="number" min="0" max="3650" name="backup_max_age_days" value="<?= (int) ($backupRetention['max_age_days'] ?? 0) ?>">
        </label>
        <label>
          <input type="checkbox" name="backup_auto_prune" value="1" <?= !empty($backupRetention['auto_prune']) ? 'checked' : '' ?>>
          Auto-prune after config writes/restores/backups
        </label>
        <p><button class="btn small" type="submit">Save retention</button></p>
      </form>
      <form method="post" action="?action=prune_config_backups" onsubmit="return confirm('Prune old config backups now using current retention policy?');">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <p><button class="btn small danger" type="submit">Prune now</button></p>
      </form>
    </div>
    <div class="form-card stacked-form">
      <h4>Import</h4>
      <form method="post" action="?action=preview_config_snapshot">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <label>Snapshot JSON<br>
          <textarea name="snapshot_json" rows="8" style="width:100%;background:#020617;border:1px solid #1f2937;border-radius:8px;color:#e5e7eb;" placeholder='Paste full snapshot JSON here'></textarea>
        </label>
        <span class="hint">If the snapshot contains <code>***redacted***</code> secret placeholders, current live secret values are preserved during import.</span>
        <p><button class="btn small" type="submit">Preview import diff</button></p>
      </form>
    </div>
  </div>

  <div class="share-config">
    <h4>Backup summary</h4>
    <p class="muted">
      <strong>Count:</strong> <?= (int) $backupSummary['count'] ?> ·
      <strong>Total size:</strong> <?= number_format((int) $backupSummary['total_size']) ?> bytes ·
      <strong>Newest:</strong> <?= !empty($backupSummary['newest_mtime']) ? date('Y-m-d H:i:s', (int) $backupSummary['newest_mtime']) : '-' ?> ·
      <strong>Oldest:</strong> <?= !empty($backupSummary['oldest_mtime']) ? date('Y-m-d H:i:s', (int) $backupSummary['oldest_mtime']) : '-' ?>
    </p>
  </div>

  <h4>Available backups</h4>
  <?php if (!$backupItems): ?>
    <p class="muted">No config backups found yet.</p>
  <?php else: ?>
    <form method="post" action="?action=download_all_config_backups" style="margin: 0 0 10px 0;">
      <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
      <button class="btn small" type="submit">Download all as ZIP</button>
    </form>
    <table>
      <thead>
        <tr>
          <th>Filename</th>
          <th>Modified</th>
          <th>Size</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($backupItems as $item): ?>
        <tr>
          <td><code><?= rdv_html((string) $item['name']) ?></code></td>
          <td><?= !empty($item['mtime']) ? date('Y-m-d H:i:s', (int) $item['mtime']) : '-' ?></td>
          <td><?= number_format((int) $item['size']) ?> bytes</td>
          <td>
            <form class="inline" method="post" action="?action=download_config_backup" style="display:inline;">
              <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="backup_name" value="<?= rdv_html((string) $item['name']) ?>">
              <button class="btn small" type="submit">Download</button>
            </form>
            <form class="inline" method="post" action="?action=restore_config_backup" style="display:inline;" onsubmit="return confirm('Restore this backup and replace current config.php?');">
              <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
              <input type="hidden" name="backup_name" value="<?= rdv_html((string) $item['name']) ?>">
              <button class="btn small danger" type="submit">Restore</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($importPreview): ?>
  <?php
    $importDiff = is_array($importPreview['diff'] ?? null) ? $importPreview['diff'] : ['rows' => [], 'total' => 0, 'truncated' => false];
    $importRows = is_array($importDiff['rows'] ?? null) ? $importDiff['rows'] : [];
    $importTotal = (int) ($importDiff['total'] ?? 0);
    $importTruncated = !empty($importDiff['truncated']);
  ?>
  <div class="share-config">
    <h3>Import Preview</h3>
    <p class="muted">
      Detected <strong><?= $importTotal ?></strong> change(s) versus current config.
      <?php if ($importTruncated): ?>
        Showing first <?= count($importRows) ?> entries.
      <?php endif; ?>
    </p>
    <p>
      <form class="inline" method="post" action="?action=apply_config_snapshot" style="display:inline;">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <button class="btn small" type="submit">Apply import</button>
      </form>
      <form class="inline" method="post" action="?action=cancel_config_snapshot" style="display:inline;">
        <input type="hidden" name="_rdv_csrf" value="<?= rdv_html(rdv_csrf_token()) ?>">
        <button class="btn small danger" type="submit">Cancel preview</button>
      </form>
    </p>
    <?php if (!$importRows): ?>
      <p class="muted">No differences found.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Path</th>
            <th>Status</th>
            <th>Current</th>
            <th>Incoming</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($importRows as $row): ?>
          <tr>
            <td><code><?= rdv_html((string) ($row['path'] ?? '')) ?></code></td>
            <td><?= rdv_html((string) ($row['status'] ?? 'changed')) ?></td>
            <td><code><?= rdv_html((string) ($row['current'] ?? '')) ?></code></td>
            <td><code><?= rdv_html((string) ($row['incoming'] ?? '')) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="share-config">
  <h3>Security diagnostics</h3>
  <p class="muted">
    Effective state:
    <strong>headers</strong>=<?= $headerDiag['enabled'] ? 'on' : 'off' ?> ·
    <strong>nosniff</strong>=<?= $headerDiag['nosniff'] ? 'on' : 'off' ?> ·
    <strong>X-Frame-Options</strong>=<?= rdv_html((string) $headerDiag['x_frame_options']) ?> ·
    <strong>CSP</strong>=<?= $headerDiag['csp_enabled'] ? 'on' : 'off' ?> ·
    <strong>CSP override</strong>=<?= rdv_html((string) $headerDiag['csp_policy_override']) ?>
  </p>
  <p class="muted">
    <strong>Referrer-Policy:</strong> <code><?= rdv_html((string) $headerDiag['referrer_policy']) ?></code><br>
    <strong>Permissions-Policy:</strong> <code><?= rdv_html((string) $headerDiag['permissions_policy']) ?></code><br>
    <strong>frame-ancestors:</strong> <code><?= rdv_html((string) $headerDiag['frame_ancestors']) ?></code>
  </p>

  <?php if (!empty($headerDiag['warnings'])): ?>
    <div class="alert error">
      <?php foreach ($headerDiag['warnings'] as $warning): ?>
        <div><?= rdv_html((string) $warning) ?></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert success">No obvious security-header conflicts detected.</div>
  <?php endif; ?>

  <p><strong>Recommended profiles</strong></p>
  <p>
    <button class="btn small" type="button" id="apply-cross-origin-profile">Apply Cross-origin Embed Profile</button>
    <button class="btn small" type="button" id="apply-same-origin-profile">Apply Same-origin Locked Profile</button>
    <button class="btn small" type="button" id="verify-security-headers">Verify Current Headers</button>
  </p>
  <p id="security-profile-status" class="muted" style="display:none;"></p>
  <div id="security-headers-verify-results" class="muted" style="display:none;"></div>
  <div class="settings-grid">
    <div class="form-card stacked-form">
      <h4>Cross-origin Roundcube Embed</h4>
      <pre style="white-space:pre-wrap;line-height:1.5;background:#020617;border-radius:10px;padding:12px;"><?= rdv_html((string) $headerDiag['profile_cross_origin']) ?></pre>
    </div>
    <div class="form-card stacked-form">
      <h4>Same-origin Locked Down</h4>
      <pre style="white-space:pre-wrap;line-height:1.5;background:#020617;border-radius:10px;padding:12px;"><?= rdv_html((string) $headerDiag['profile_same_origin']) ?></pre>
    </div>
  </div>
</div>

<?php
endif;

rdv_render_footer();
