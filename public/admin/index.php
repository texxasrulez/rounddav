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

use PDO;
use PDOException;
use RoundDAV\Bookmarks\BookmarkRepository;
use RoundDAV\Bookmarks\BookmarkService;

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
    if (@file_put_contents($configFile, $data, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write config.php');
    }
}

function rdv_apply_config_updates(array $config, array $input): array
{
    foreach (['log', 'files', 'security', 'provision', 'sso', 'locale', 'admin'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            $config[$section] = [];
        }
    }
    if (!isset($config['security']['rate_limit']) || !is_array($config['security']['rate_limit'])) {
        $config['security']['rate_limit'] = [];
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

    $config['security']['rate_limit']['enabled']      = !empty($input['rate_limit_enabled']);
    $config['security']['rate_limit']['max_requests'] = max(1, (int) ($input['rate_limit_max'] ?? 100));
    $config['security']['rate_limit']['window_sec']   = max(1, (int) ($input['rate_limit_window'] ?? 60));
    $config['security']['rate_limit']['block_minutes'] = max(1, (int) ($input['rate_limit_block'] ?? 5));

    $config['provision']['baseurl']          = trim((string) ($input['provision_baseurl'] ?? ($config['provision']['baseurl'] ?? '')));
    $config['provision']['principal_prefix'] = trim((string) ($input['provision_principal_prefix'] ?? ($config['provision']['principal_prefix'] ?? 'principals')));

    $config['sso']['enabled'] = !empty($input['sso_enabled']);
    $config['sso']['ttl']     = max(60, (int) ($input['sso_ttl'] ?? 600));
    if (isset($input['sso_secret'])) {
        $ssoSecret = trim((string) $input['sso_secret']);
        if ($ssoSecret !== '') {
            $config['sso']['secret'] = $ssoSecret;
        }
    }

    $config['locale']['default'] = trim((string) ($input['locale_default'] ?? ($config['locale']['default'] ?? 'en_US')));

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

function rdv_redirect_action(string $requested): string
{
    $allowed = ['sharing', 'sharing_calendar', 'sharing_addressbook', 'sharing_bookmarks', 'sharing_files'];
    return in_array($requested, $allowed, true) ? $requested : 'sharing';
}

$action = $_GET['action'] ?? 'dashboard';
$error  = null;

// Handle login/logout early
if ($action === 'do_login') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (
        $user === ($adminCfg['username'] ?? '') &&
        !empty($adminCfg['password_hash']) &&
        password_verify($pass, $adminCfg['password_hash'])
    ) {
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
} elseif (in_array($action, ['create_global_collection', 'delete_global_collection', 'add_global_permission', 'delete_global_permission'], true)) {
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
} elseif ($action === 'update_config') {
    rdv_require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $config = rdv_apply_config_updates($config, $_POST);
            rdv_write_config_file($config, RDV_CONFIG_FILE);
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

  <?php $configSaved = !empty($_GET['saved']); ?>
  <hr>
<h2>Server configuration</h2>
<p class="muted">
  Update <code><?= rdv_html(basename(RDV_CONFIG_FILE)) ?></code> directly from the browser.
  We only expose security-relevant knobs here; database credentials remain read-only.
</p>
<?php if ($configSaved): ?>
  <div class="alert success">Configuration saved.</div>
<?php elseif (!empty($error)): ?>
  <div class="alert error"><?= rdv_html($error) ?></div>
<?php endif; ?>

<form method="post" action="?action=update_config">
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
    </div>
    <div class="form-card stacked-form">
      <h3>Provisioning</h3>
      <label>Base URL<br>
        <input type="text" name="provision_baseurl" value="<?= rdv_html((string) ($config['provision']['baseurl'] ?? '')) ?>">
      </label>
      <label>Principal prefix<br>
        <input type="text" name="provision_principal_prefix" value="<?= rdv_html((string) ($config['provision']['principal_prefix'] ?? 'principals')) ?>">
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
  </div>
  <p><button class="btn" type="submit">Save configuration</button></p>
</form>
<p class="muted">Config file: <code><?= rdv_html(RDV_CONFIG_FILE) ?></code>. Password hashes are stored securely; plaintext passwords are never echoed.</p>

<?php
endif;

rdv_render_footer();
