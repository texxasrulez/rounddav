<?php
declare(strict_types=1);

// rounddav/public/files/index.php
//
// Simple web UI for per-user + shared file storage on top of the
// filesystem root configured in config['files']['root'].
//
// Auth: RoundDAV users (rounddav_users table, same credentials as DAV/Roundcube).

session_start();

// Autoload + config
$baseDir  = dirname(__DIR__, 2); // from public/files -> public -> base
$autoload = $baseDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('Autoloader not found');
}
require $autoload;

$configFile = $baseDir . '/config/config.php';
if (!file_exists($configFile)) {
    die('Config not found (config.php missing). Please run the installer.');
}
$config = require $configFile;

$db = $config['database'] ?? [];
try {
    $pdo = new \PDO(
        $db['dsn']      ?? '',
        $db['user']     ?? '',
        $db['password'] ?? '',
        $db['options']  ?? []
    );
} catch (\PDOException $e) {
    die('DB connection failed');
}

// Files configuration
$filesCfg = $config['files'] ?? null;
if (!$filesCfg || empty($filesCfg['root'])) {
    die('File storage not configured. Please set config["files"]["root"].');
}

$filesRoot = rtrim($filesCfg['root'], DIRECTORY_SEPARATOR);

/**
 * Return the currently logged-in RoundDAV username, or null.
 */
function rdv_files_current_user(): ?string
{
    return $_SESSION['rounddav_files_user'] ?? null;
}

/**
 * Require a logged-in user, or redirect to login.
 */
function rdv_files_require_login(): void
{
    if (!rdv_files_current_user()) {
        header('Location: ?action=login');
        exit;
    }
}

/**
 * Very simple guard against directory traversal in relative paths.
 */
function rdv_sanitize_relpath(string $path): string
{
    $path = str_replace(["\\", "\0"], '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

/**
 * Ensure a directory exists (mkdir -p behavior).
 */
function rdv_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
}

/**
 * Classify file for preview type (for future modal viewer).
 */
function rdv_preview_type(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp3','ogg','wav','flac','m4a'], true)) {
        return 'audio';
    }
    if (in_array($ext, ['mp4','m4v','mov','webm','ogv'], true)) {
        return 'video';
    }
    if (in_array($ext, ['pdf'], true)) {
        return 'pdf';
    }
    if (in_array($ext, ['txt','log','md','markdown','csv','json','xml','ini','conf'], true)) {
        return 'text';
    }
    if (in_array($ext, ['html','htm'], true)) {
        return 'html';
    }
    return 'other';
}

function rdv_can_preview(string $name): bool
{
    return rdv_preview_type($name) !== 'other';
}

// Handle actions
$action = $_GET['action'] ?? 'browse';

// Login / logout
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username !== '' && $password !== '') {
            $stmt = $GLOBALS['pdo']->prepare('SELECT username, password_hash, active FROM rounddav_users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['active']) && !empty($row['password_hash']) &&
                password_verify($password, $row['password_hash'])) {
                $_SESSION['rounddav_files_user'] = $row['username'];
                header('Location: ?action=browse');
                exit;
            }

            $error = 'Invalid credentials';
        } else {
            $error = 'Username and password required';
        }
    }

    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8" />
        <title>RoundDAV Files</title>
        <style>
			:root {
				--login-bgtext: #141414;
				--login-bgcolor: #eaeaea;
				--login-cardbg: #e8e8e8;
				--login-cardborder: #282828;				
				--login-inputborder: #374151;
				--login-inputbg: #ccc;
				--login-inputtext: #e5e7eb;
				--login-button: #7a7a7a;
				--login-buttontext: #fff;
				--login-buttonhover: #3d3d3d;
				--login-error: #ff0000;
			}
            body {
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                margin: 0;
                padding: 0;
                background: var(--login-bgcolor);
                color: var(--login-bgtext);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            .card {
                background: var(--login-cardbg);
                border-radius: 10px;
                padding: 22px 24px;
                box-shadow: 0 18px 45px rgba(0,0,0,0.6);
                width: 340px;
                border: 1px solid var(--login-cardborder);
            }
            .logo {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
            }
            .logo img {
                height: 40px;
            }
            h1 {
                font-size: 18px;
			    position: relative;
			    top: 5px;
            }
            label {
                display: block;
                margin-top: 12px;
                font-size: 13px;
            }
            input[type="text"],
            input[type="password"] {
                width: 100%;
                box-sizing: border-box;
                padding: 6px 8px;
                border-radius: 6px;
                border: 1px solid var(--login-inputborder);
                background: var(--login-inputbg);
                color: var(--login-inputtext);
                margin-top: 4px;
            }
            button {
                margin-top: 18px;
                width: 100%;
                padding: 8px 10px;
                border-radius: 6px;
                border: none;
                background: var(--login-button);
                color: var(--login-buttontext);
                font-weight: 600;
                cursor: pointer;
            }
            button:hover {
                background: var(--login-buttonhover);
            }
            .error {
                color: var(--login-error);
                font-size: 13px;
                margin-bottom: 8px;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <div class="logo">
            <img src="../assets/logo-rounddav.svg" alt="RoundDAV logo" />
        </div>
            <h1>Cloud Storage</h1>
            <hr>
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <form method="post">
            <label>Username
                <input type="text" name="username" autocomplete="username" />
            </label>
            <label>Password
                <input type="password" name="password" autocomplete="current-password" />
            </label>
            <button type="submit">Log in</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'logout') {
    unset($_SESSION['rounddav_files_user']);
    header('Location: ?action=login');
    exit;
}

// Download handler (must be logged in)
if ($action === 'download') {
    rdv_files_require_login();
    $user = rdv_files_current_user();

    $area = ($_GET['area'] ?? 'user') === 'shared' ? 'shared' : 'user';
    $rel  = rdv_sanitize_relpath($_GET['path'] ?? '');
    $file = $_GET['file'] ?? '';

    if ($file === '') {
        http_response_code(400);
        echo 'Missing file parameter';
        exit;
    }

    $base = $filesRoot . DIRECTORY_SEPARATOR . ($area === 'shared' ? 'shared' : ('users' . DIRECTORY_SEPARATOR . $user));
    rdv_ensure_dir($base);

    $fullPath = $base . DIRECTORY_SEPARATOR . ($rel === '' ? '' : $rel . DIRECTORY_SEPARATOR) . $file;

    if (!is_file($fullPath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $basename = basename($fullPath);
    $inline = isset($_GET['inline']) && $_GET['inline'] === '1';

    if ($inline) {
        $mime = @mime_content_type($fullPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes($basename) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($basename) . '"');
    }

    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

// For all other actions we require login
rdv_files_require_login();
$user = rdv_files_current_user();

$area    = ($_GET['area'] ?? 'user') === 'shared' ? 'shared' : 'user';
$relPath = rdv_sanitize_relpath($_GET['path'] ?? '');

// --- Admin detection (used for hiding delete in shared for non-admins) ---
$adminConf = $config['admin'] ?? [];
$admin_id  = $adminConf['password'] ?? '';
$is_admin  = ($user && $admin_id && $user === $admin_id);

// Whether delete is allowed in this view
$canDeleteHere = !($area === 'shared' && !$is_admin);

$base = $filesRoot . DIRECTORY_SEPARATOR . ($area === 'shared' ? 'shared' : ('users' . DIRECTORY_SEPARATOR . $user));
rdv_ensure_dir($base);

$dirPath = $base;
if ($relPath !== '') {
    $dirPath .= DIRECTORY_SEPARATOR . $relPath;
}
rdv_ensure_dir($dirPath);

// Handle POST actions: upload, mkdir, delete, delete_multi, move, move_multi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction  = $_POST['do'] ?? '';
    $redirectUrl = '?action=browse&area=' . urlencode($area) . '&path=' . urlencode($relPath);

    if ($postAction === 'upload' && isset($_FILES['file'])) {
        $files = $_FILES['file'];

        // Support both single and multiple (file vs file[])
        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $name   = basename($files['name'][$i]);
                    $target = $dirPath . DIRECTORY_SEPARATOR . $name;
                    @move_uploaded_file($files['tmp_name'][$i], $target);
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $name   = basename($files['name']);
                $target = $dirPath . DIRECTORY_SEPARATOR . $name;
                @move_uploaded_file($files['tmp_name'], $target);
            }
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($postAction === 'mkdir') {
        $name = trim((string)($_POST['dirname'] ?? ''));
        if ($name !== '') {
            $name = str_replace(['/', '\\'], '_', $name);
            $target = $dirPath . DIRECTORY_SEPARATOR . $name;
            rdv_ensure_dir($target);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($postAction === 'delete') {
        $item = $_POST['item'] ?? '';
        if ($item !== '') {
            $item = basename($item);
            $target = $dirPath . DIRECTORY_SEPARATOR . $item;
            if (is_file($target)) {
                @unlink($target);
            } elseif (is_dir($target)) {
                // Only remove empty directories
                @rmdir($target);
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($postAction === 'delete_multi') {
        $itemsJson = (string)($_POST['items'] ?? '[]');
        $fromPath  = rdv_sanitize_relpath((string)($_POST['from_path'] ?? ''));

        $items = json_decode($itemsJson, true);
        if (!is_array($items)) {
            $items = [];
        }

        $baseForArea = $filesRoot . DIRECTORY_SEPARATOR . ($area === 'shared' ? 'shared' : ('users' . DIRECTORY_SEPARATOR . $user));

        $fromDir = $baseForArea;
        if ($fromPath !== '') {
            $fromDir .= DIRECTORY_SEPARATOR . $fromPath;
        }

        foreach ($items as $itemName) {
            $itemName = basename((string)$itemName);
            if ($itemName === '') {
                continue;
            }
            $target = $fromDir . DIRECTORY_SEPARATOR . $itemName;
            if (is_file($target)) {
                @unlink($target);
            } elseif (is_dir($target)) {
                @rmdir($target);
            }
        }

        $redirectUrl = '?action=browse&area=' . urlencode($area) . '&path=' . urlencode($fromPath);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($postAction === 'move') {
        $item     = basename((string)($_POST['item'] ?? ''));
        $fromPath = rdv_sanitize_relpath((string)($_POST['from_path'] ?? ''));
        $toPath   = rdv_sanitize_relpath((string)($_POST['to_path'] ?? ''));

        if ($item !== '') {
            $baseForArea = $filesRoot . DIRECTORY_SEPARATOR . ($area === 'shared' ? 'shared' : ('users' . DIRECTORY_SEPARATOR . $user));

            $fromDir = $baseForArea;
            if ($fromPath !== '') {
                $fromDir .= DIRECTORY_SEPARATOR . $fromPath;
            }

            $toDir = $baseForArea;
            if ($toPath !== '') {
                $toDir .= DIRECTORY_SEPARATOR . $toPath;
            }

            rdv_ensure_dir($toDir);

            $src = $fromDir . DIRECTORY_SEPARATOR . $item;
            $dst = $toDir . DIRECTORY_SEPARATOR . $item;

            if (file_exists($src) && $src !== $dst) {
                @rename($src, $dst);
            }
        }

        $redirectUrl = '?action=browse&area=' . urlencode($area) . '&path=' . urlencode($toPath);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($postAction === 'move_multi') {
        $itemsJson = (string)($_POST['items'] ?? '[]');
        $fromPath  = rdv_sanitize_relpath((string)($_POST['from_path'] ?? ''));
        $toPath    = rdv_sanitize_relpath((string)($_POST['to_path'] ?? ''));

        $items = json_decode($itemsJson, true);
        if (!is_array($items)) {
            $items = [];
        }

        $baseForArea = $filesRoot . DIRECTORY_SEPARATOR . ($area === 'shared' ? 'shared' : ('users' . DIRECTORY_SEPARATOR . $user));

        $fromDir = $baseForArea;
        if ($fromPath !== '') {
            $fromDir .= DIRECTORY_SEPARATOR . $fromPath;
        }

        $toDir = $baseForArea;
        if ($toPath !== '') {
            $toDir .= DIRECTORY_SEPARATOR . $toPath;
        }

        rdv_ensure_dir($toDir);

        foreach ($items as $itemName) {
            $itemName = basename((string)$itemName);
            if ($itemName === '') {
                continue;
            }
            $src = $fromDir . DIRECTORY_SEPARATOR . $itemName;
            $dst = $toDir . DIRECTORY_SEPARATOR . $itemName;
            if (file_exists($src) && $src !== $dst) {
                @rename($src, $dst);
            }
        }

        $redirectUrl = '?action=browse&area=' . urlencode($area) . '&path=' . urlencode($toPath);
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Listing
$entries = [];
$handle = opendir($dirPath);
if ($handle) {
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dirPath . DIRECTORY_SEPARATOR . $entry;
        $entries[] = [
            'name' => $entry,
            'is_dir' => is_dir($full),
            'size' => is_file($full) ? filesize($full) : null,
            'mtime' => filemtime($full),
        ];
    }
    closedir($handle);
}

// Map: folder name → has any content (for tiny badge)
$folderHasContent = [];
foreach ($entries as $e) {
    if (!$e['is_dir']) {
        continue;
    }
    $folderPath = $dirPath . DIRECTORY_SEPARATOR . $e['name'];
    $hasContent = false;

    if ($dh = opendir($folderPath)) {
        while (($sub = readdir($dh)) !== false) {
            if ($sub === '.' || $sub === '..') {
                continue;
            }
            $hasContent = true;
            break;
        }
        closedir($dh);
    }

    $folderHasContent[$e['name']] = $hasContent;
}

usort($entries, function ($a, $b) {
    if ($a['is_dir'] && !$b['is_dir']) return -1;
    if (!$a['is_dir'] && $b['is_dir']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

// Viewable extensions (for context menu "View" option)
$viewableExtensions = [
    'txt','log','md','markdown',
    'jpg','jpeg','png','gif','webp','svg',
    'pdf',
    'html','htm',
    'csv','json','xml','ini','conf',
    // audio
    'mp3','ogg','wav','flac','m4a',
    // video
    'mp4','m4v','mov','webm','ogv',
];

?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>RoundDAV Files</title>
    <style>
			:root {				
				--files-bgcolor: #eaeaea;
				--files-bgtext: #141414;
				--files-cardbg: #d6d6d6;
				--files-cardborder: #282828;
				--files-headerbborder: #282828;
				--files-headerbg: #d6d6d6;
				--files-headerright: #010f20;
				--files-headerrighta: #010f20;
				--files-headerpath: #323232;
				--files-tbbutton: #555555;
				--files-tbbuttontext: #fff;
				--files-tbbuttonhover: #505050;
				--files-thborder: #5c5c5c;
				--files-thtext: #fff;
				--files-thbg: #5c5c5c;
				--files-trntbge: #e0e0e0;
				--files-trntbgo: #dbdbdb;
				--files-trnttexte: #000;
				--files-trnttexto: #000;
				--files-visitedtext: #000;
				--files-deletebtnbg: #b50000;
				--files-deletebtntext: #f9fafb;
				--files-deletebtnhoverbg: #b91c1c;
				--files-deletbtnrgba: 181, 0, 0;
				--files-deletbtnhoverrgba: 181, 0, 0;
				--files-toolbarbtnbgrgba: 85, 85, 85;
				--files-toolbarbtnbghoverrgba: 80, 80, 80;
			}
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background: var(--files-bgcolor);
            color: var(--files-bgtext);
        }
		.card {
			max-width: 100vw;
			margin: 15px 15px 15px 15px;
			background: var(--files-cardbg);
			border-radius: 4px;
			padding: 5px 5px 5px 5px;
			box-shadow: 0 25px 50px -12px rgba(15,23,42,0.07);
			border: 1px solid var(--files-cardborder);
		}
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 18px;
            background: var(--files-headerbg);
            border-bottom: 1px solid var(--files-headerbborder);
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
        header h1 {
            font-size: 18px;
			position: relative;
			top: 6px;
        }
        header .right {
            font-size: 15px;
            color: var(--files-headerright);
			position: relative;
			top: -15px;
        }
        header .right a {
            color: var(--files-headerrighta);
            text-decoration: none;
            margin-left: 1px;
        }
        main {
            padding: 16px 18px;
        }
        .path {
            font-size: 15px;
            color: var(--files-headerpath);
            margin-bottom: 8px;
        }
        .area-label {
            font-weight: 600;
        }
        .area-label.area-user {
            color: inherit;
            font-size: 1em;
        }
        .area-label.area-shared {
            color: #fff;
            background: #b91c1c;
			border: 1px solid #b91c1c;
			padding: 2px;
			margin-bottom: 2px;
			border-radius: 2px;
		    box-shadow: 0 0 2px 2px rgb(185, 28, 28, 0.5);
        }
        .toolbar {
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .toolbar form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .toolbar input[type="file"],
        .toolbar input[type="text"] {
            font-size: 12px;
        }
        .toolbar button {
            padding: 4px 6px;
            border-radius: 4px;
            border: none;
            background: var(--files-tbbutton);
            color: var(--files-tbbuttontext);
            font-size: 11px;
            cursor: pointer;
		    background: rgba(var(--files-toolbarbtnbgrgba), 0.95);
		    backdrop-filter: blur(5px);
		    -webkit-backdrop-filter: blur(5px);
		    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .toolbar button:hover {
            background: var(--files-tbbuttonhover);
		    background: rgba(var(--files-toolbarbtnbghoverrgba), 0.5);
		    backdrop-filter: blur(5px);
		    -webkit-backdrop-filter: blur(5px);
		    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .toolbar-right {
			display: flex;		
			position: absolute;
			right: 40px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 15px;
        }
        th, td {
            border: 1px solid var(--files-thborder);
            color: var(--files-thtext);
            padding: 6px 8px;
        }
        th {
            background: var(--files-thbg);
        }
        td {
            font-style: italic;
        }
        tr:nth-child(even) td {
            background: var(--files-trntbge);
            color: var(--files-trnttexte);
            font-size: 11px;
        }
        tr:nth-child(odd) td {
            background: var(--files-trntbgo);
            color: var(--files-trnttexto);
            font-size: 11px;
        }
        a {
            color: var(--files-visitedtext);
            font-size: 15px;
            font-weight: normal;
            text-decoration: none;
            font-style: normal;
        }
        .folder-label a {
            font-weight: bold;
        }
        .delete-button {
            padding: 4px 6px;
            border-radius: 4px;
            border: none;
            background: var(--files-deletebtnbg);
            color: var(--files-deletebtntext);
            font-size: 11px;
            cursor: pointer;
		    background: --filesdeletbtnrgba0.95);
		    backdrop-filter: blur(5px);
		    -webkit-backdrop-filter: blur(5px);
		    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);

        }
        .delete-button:hover {
            background: var(--files-deletebtnhoverbg);
		    background: rgba(var(--files-deletbtnhoverrgba), 0.5);
		    backdrop-filter: blur(5px);
		    -webkit-backdrop-filter: blur(5px);
		    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
    
        .dropzone {
            min-width: 180px;
            padding: 10px 12px;
            border: 2px dashed var(--files-cardborder);
            border-radius: 6px;
            font-size: 12px;
            color: var(--files-bgtext);
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .dropzone:hover,
        .dropzone.is-dragover {
            background: rgba(255,255,255,0.7);
        }
        .dropzone-primary {
            font-weight: 600;
            margin-bottom: 2px;
        }
        .dropzone-secondary {
            font-size: 11px;
            opacity: 0.8;
        }
        .file-context-menu {
            position: absolute;
            background: var(--files-cardbg);
            border: 1px solid var(--files-cardborder);
            border-radius: 6px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            min-width: 130px;
            padding: 4px 0;
            z-index: 1000;
            font-size: 12px;
        }
        .file-context-menu button {
            display: block;
            width: 100%;
            padding: 6px 12px;
            border: 0;
            background: transparent;
            color: var(--files-bgtext);
            text-align: left;
            cursor: pointer;
        }
        .file-context-menu button:hover {
            background: rgba(255,255,255,0.2);
        }
        /* Folder "has content" badge */
        .folder-row.folder-nonempty .folder-label::after {
            content: '🗐';
            display: inline-block;
            margin-top: -15px;
            margin-left: 4px;
            font-size: 15px;
            font-style: normal;
        }
        /* Multi-select visual */
        .file-row.selected td {
            outline: 2px solid #2563eb;
            outline-offset: -1px;
        }
        /* Modal preview */
        .rdv-preview-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(9, 9, 11, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .rdv-preview-backdrop.active {
            display: flex;
        }
        .rdv-preview-modal {
            width: min(960px, 90vw);
            height: min(640px, 90vh);
            background: #d6d6d6 !important;
            color: #f3f4f6;
            border-radius: 10px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.75);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.15);
            position: relative;
        }
        .rdv-preview-header {
            padding: 10px 16px;
            background: rgba(92, 92, 92,0.85);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .rdv-preview-title {
            font-size: 15px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .rdv-preview-close {
            background: transparent;
            border: 0;
            color: #fca5a5;
            cursor: pointer;
            font-size: 18px;
            padding: 2px 6px;
        }
        .rdv-preview-content {
            flex: 1;
            background: #eaeaea;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            overflow: hidden;
        }
        .rdv-preview-content iframe,
        .rdv-preview-content img,
        .rdv-preview-content video,
        .rdv-preview-content audio {
            max-width: 90%;
            max-height: 80%;
        }
        .rdv-preview-content pre {
            max-width: 100%;
            max-height: 100%;
            overflow: auto;
            background: #d6d6d6;
            padding: 16px;
            border-radius: 8px;
            font-size: 13px;
            color: #e5e7eb;
        }
        .rdv-preview-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: none;
            background: rgba(15,23,42,0.65);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .rdv-preview-nav:hover {
            background: rgba(59,130,246,0.7);
        }
        .rdv-preview-nav.prev {
            left: 16px;
        }
        .rdv-preview-nav.next {
            right: 16px;
        }
        .rdv-preview-empty {
            font-size: 14px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
<header>
    <div class="logo-wrap">
        <img src="../assets/globe-rounddav.svg" alt="RoundDAV logo" />
        <h1>Cloud Storage</h1>
    </div>    
        
		<span class="area-label toolbar-center <?= $area === 'shared' ? 'area-shared' : 'area-user' ?>">
            <?= $area === 'shared' ? 'SHARED FILES' : null ?>
        </span>
        
    <div class="right">
        <?= htmlspecialchars($user, ENT_QUOTES) ?> |
        <a href="?action=browse&amp;area=user">My files</a> |
        <a href="?action=browse&amp;area=shared">Shared Files</a>
    </div>
</header>
    <div class="card">
<main>
    <div class="path">
        Area:&nbsp;&nbsp;
        <span class="<?= $area === 'shared' ? 'area-shared' : 'area-user' ?>">
            <?= $area === 'shared' ? 'Shared' : 'My files' ?>
        </span>
        &nbsp;&nbsp;-&nbsp;&nbsp;
        Path: /
        <?php if ($relPath !== ''): ?>
            <?= htmlspecialchars($relPath, ENT_QUOTES) ?>/ 
        <?php endif; ?>
    </div>

    <table>
        <tr>
            <th>Choose File to Upload</th>
        </tr>
        <?php if ($relPath !== ''):
            $parent = explode('/', $relPath);
            array_pop($parent);
            $parentPath = implode('/', $parent);
            ?>
            <tr class="folder-row" data-path="<?= htmlspecialchars($parentPath, ENT_QUOTES) ?>">
                <td><a href="?action=browse&amp;area=<?= urlencode($area) ?>&amp;path=<?= urlencode($parentPath) ?>">[  ..  ]</a></td>
            </tr>
        <?php endif; ?>

        <?php foreach ($entries as $idx => $e): ?>
            <?php if ($e['is_dir']): ?>
            <tr class="folder-row <?= !empty($folderHasContent[$e['name']]) ? 'folder-nonempty' : '' ?>"
                data-path="<?= htmlspecialchars(trim($relPath . '/' . $e['name'], '/'), ENT_QUOTES) ?>">
                <td width="30%">
                    <span class="folder-label">
                        <a href="?action=browse&amp;area=<?= urlencode($area) ?>&amp;path=<?= urlencode(trim($relPath . '/' . $e['name'], '/')) ?>">
                            <?= htmlspecialchars($e['name'], ENT_QUOTES) ?></a>
                    </span>
                </td>
            </tr>
            <?php else: ?>
            <tr class="file-row" data-index="<?= (int)$idx ?>"
                data-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>"
                data-path="<?= htmlspecialchars($relPath, ENT_QUOTES) ?>">
                <td width="30%">
                    <a href="?action=download&amp;area=<?= urlencode($area) ?>&amp;path=<?= urlencode($relPath) ?>&amp;file=<?= urlencode($e['name']) ?>"
                       class="file-download-link"
                       data-file-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>"
                       data-preview-type="<?= htmlspecialchars(rdv_preview_type($e['name']), ENT_QUOTES) ?>">
                        <?= htmlspecialchars($e['name'], ENT_QUOTES) ?></a>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
            <tr><td colspan="1"><em>Empty</em></td></tr>
        <?php endif; ?>
    </table>
  </div>
</main>

<!-- Hidden move forms for internal drag-to-move -->
<form id="move-form" method="post" style="display:none;">
    <input type="hidden" name="do" value="move" />
    <input type="hidden" name="item" id="move-item" />
    <input type="hidden" name="from_path" id="move-from" />
    <input type="hidden" name="to_path" id="move-to" />
</form>

<form id="bulk-move-form" method="post" style="display:none;">
    <input type="hidden" name="do" value="move_multi" />
    <input type="hidden" name="items" id="bulk-move-items" />
    <input type="hidden" name="from_path" id="bulk-move-from" />
    <input type="hidden" name="to_path" id="bulk-move-to" />
</form>

<form id="bulk-delete-form" method="post" style="display:none;">
    <input type="hidden" name="do" value="delete_multi" />
    <input type="hidden" name="items" id="bulk-delete-items" />
    <input type="hidden" name="from_path" id="bulk-delete-from" />
</form>

<!-- Context menu -->
<div id="file-context-menu" class="file-context-menu" style="display:none;">
    <button type="button" data-action="view">View</button>
    <button type="button" data-action="download">Download</button>
</div>

<!-- Modal preview -->
<div id="rdv-preview-backdrop" class="rdv-preview-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="rdv-preview-modal">
        <div class="rdv-preview-header">
            <div class="rdv-preview-title" id="rdv-preview-title"></div>
            <button type="button" class="rdv-preview-close" aria-label="Close preview">×</button>
        </div>
        <div class="rdv-preview-content" id="rdv-preview-content">
            <div class="rdv-preview-empty">Select a supported file to preview.</div>
        </div>
        <button type="button" class="rdv-preview-nav prev" aria-label="Previous file">‹</button>
        <button type="button" class="rdv-preview-nav next" aria-label="Next file">›</button>
    </div>
</div>

<script>
(function() {
    var uploadForm = document.getElementById('rdv-upload-form');
    var fileInput  = document.getElementById('rdv-file-input');
    var dropzone   = document.getElementById('rdv-dropzone');

    function prevent(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    if (uploadForm && fileInput && dropzone) {
        ['dragenter', 'dragover'].forEach(function(evt) {
            dropzone.addEventListener(evt, function(e) {
                prevent(e);
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function(evt) {
            dropzone.addEventListener(evt, function(e) {
                prevent(e);
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', function(e) {
            prevent(e);
            var files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) {
                return;
            }
            // Browser will respect "multiple" and name="file[]"
            // so just assign and submit as before.
            try {
                fileInput.files = files;
                uploadForm.submit();
            } catch (err) {
                // Fallback if assignment is blocked: let user manually pick
                alert('Your browser does not support direct drag-to-input assignment for multiple files.');
            }
        });

        dropzone.addEventListener('click', function() {
            fileInput.click();
        });
    }

    var VIEWABLE_EXTENSIONS = <?php echo json_encode($viewableExtensions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function isViewable(name) {
        if (!name) {
            return false;
        }
        var idx = name.lastIndexOf('.');
        if (idx === -1) {
            return false;
        }
        var ext = name.slice(idx + 1).toLowerCase();
        for (var i = 0; i < VIEWABLE_EXTENSIONS.length; i++) {
            if (VIEWABLE_EXTENSIONS[i] === ext) {
                return true;
            }
        }
        return false;
    }

    function previewType(name) {
        if (!name) return 'other';
        var idx = name.lastIndexOf('.');
        if (idx === -1) return 'other';
        var ext = name.slice(idx + 1).toLowerCase();
        if (['jpg','jpeg','png','gif','webp','svg'].indexOf(ext) !== -1) return 'image';
        if (['mp3','ogg','wav','flac','m4a'].indexOf(ext) !== -1) return 'audio';
        if (['mp4','m4v','mov','webm','ogv'].indexOf(ext) !== -1) return 'video';
        if (['pdf'].indexOf(ext) !== -1) return 'pdf';
        if (['txt','log','md','markdown','csv','json','xml','ini','conf'].indexOf(ext) !== -1) return 'text';
        if (['html','htm'].indexOf(ext) !== -1) return 'html';
        return 'other';
    }

    var contextMenu = document.getElementById('file-context-menu');
    var currentUrl  = null;
    var currentName = null;
    var fileList    = [];
    var previewIndex = -1;

    function buildFileList() {
        fileList = [];
        document.querySelectorAll('tr.file-row').forEach(function(row) {
            var link = row.querySelector('a.file-download-link');
            if (!link) return;
            var name = row.getAttribute('data-name') || link.getAttribute('data-file-name') || '';
            var path = row.getAttribute('data-path') || '';
            var href = link.getAttribute('href');
            fileList.push({
                name: name,
                path: path,
                href: href,
                type: previewType(name)
            });
        });
    }

    buildFileList();

    function hideMenu() {
        if (contextMenu) {
            contextMenu.style.display = 'none';
        }
    }

    document.addEventListener('click', function() {
        hideMenu();
    });

    document.addEventListener('scroll', function() {
        hideMenu();
    }, true);

    var links = document.querySelectorAll('a.file-download-link');

    function openModalForIndex(idx) {
        if (idx < 0 || idx >= fileList.length) {
            return;
        }

        previewIndex = idx;
        var entry = fileList[idx];
        var backdrop = document.getElementById('rdv-preview-backdrop');
        var titleEl  = document.getElementById('rdv-preview-title');
        var content  = document.getElementById('rdv-preview-content');
        var navPrev  = document.querySelector('.rdv-preview-nav.prev');
        var navNext  = document.querySelector('.rdv-preview-nav.next');
        if (!backdrop || !titleEl || !content || !navPrev || !navNext) {
            window.open(ensureInline(entry.href), '_blank');
            return;
        }

        titleEl.textContent = entry.name;
        content.innerHTML = '';

        var type = entry.type;
        var src  = ensureInline(entry.href);

        if (type === 'image') {
            var img = document.createElement('img');
            img.src = src;
            img.alt = entry.name;
            content.appendChild(img);
        } else if (type === 'audio') {
            var audio = document.createElement('audio');
            audio.controls = true;
            audio.src = src;
            audio.style.width = '100%';
            content.appendChild(audio);
        } else if (type === 'video') {
            var video = document.createElement('video');
            video.controls = true;
            video.src = src;
            video.style.maxHeight = '100%';
            video.style.maxWidth = '100%';
            content.appendChild(video);
        } else if (type === 'pdf') {
            var frame = document.createElement('iframe');
            frame.src = src;
            frame.style.width = '100%';
            frame.style.height = '100%';
            frame.loading = 'lazy';
            content.appendChild(frame);
        } else if (type === 'html') {
            var htmlFrame = document.createElement('iframe');
            htmlFrame.src = src;
            htmlFrame.style.width = '100%';
            htmlFrame.style.height = '100%';
            content.appendChild(htmlFrame);
        } else if (type === 'text') {
            fetch(src, { credentials: 'include' })
                .then(function(resp) {
                    if (!resp.ok) throw new Error('Failed');
                    return resp.text();
                })
                .then(function(text) {
                    var pre = document.createElement('pre');
                    pre.textContent = text;
                    content.appendChild(pre);
                })
                .catch(function() {
                    var fallback = document.createElement('div');
                    fallback.className = 'rdv-preview-empty';
                    fallback.textContent = 'Unable to load text preview.';
                    content.appendChild(fallback);
                });
        } else {
            var msg = document.createElement('div');
            msg.className = 'rdv-preview-empty';
            msg.textContent = 'Preview not available for this file type.';
            content.appendChild(msg);
        }

        backdrop.classList.add('active');
        backdrop.setAttribute('aria-hidden', 'false');

        navPrev.disabled = fileList.length <= 1;
        navNext.disabled = fileList.length <= 1;
    }

    function ensureInline(url) {
        if (url.indexOf('inline=1') !== -1) {
            return url;
        }
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'inline=1';
    }

    function closeModal() {
        var backdrop = document.getElementById('rdv-preview-backdrop');
        if (!backdrop) return;
        backdrop.classList.remove('active');
        backdrop.setAttribute('aria-hidden', 'true');
        previewIndex = -1;
    }

    function showNext(delta) {
        if (previewIndex === -1 || !fileList.length) return;
        var idx = previewIndex + delta;
        if (idx < 0) {
            idx = fileList.length - 1;
        } else if (idx >= fileList.length) {
            idx = 0;
        }
        openModalForIndex(idx);
    }

    function bindLink(link) {
        link.addEventListener('click', function(e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            if (!contextMenu) {
                return;
            }
            currentUrl  = link.getAttribute('href');
            currentName = link.getAttribute('data-file-name') || '';
            var canView = isViewable(currentName);
            var viewBtn = contextMenu.querySelector('button[data-action="view"]');
            if (viewBtn) {
                viewBtn.style.display = canView ? 'block' : 'none';
            }
            contextMenu.style.display = 'block';
            var x = e.pageX;
            var y = e.pageY;
            contextMenu.style.left = x + 'px';
            contextMenu.style.top  = y + 'px';
        });
    }

    for (var i = 0; i < links.length; i++) {
        bindLink(links[i]);
    }

    if (contextMenu) {
        contextMenu.addEventListener('click', function(e) {
            var target = e.target;
            while (target && target !== contextMenu && !target.getAttribute('data-action')) {
                target = target.parentNode;
            }
            if (!target || target === contextMenu) {
                return;
            }
            var action = target.getAttribute('data-action');
            hideMenu();
            if (!currentUrl) {
                return;
            }
            if (action === 'download') {
                window.location.href = currentUrl;
            } else if (action === 'view') {
                var idx = fileList.findIndex(function(entry) {
                    return entry.href === currentUrl;
                });
                if (idx === -1) {
                    window.open(ensureInline(currentUrl), '_blank');
                } else {
                    openModalForIndex(idx);
                }
            }
        });
    }

    // ----- Multi-select -----
    var fileRows      = Array.prototype.slice.call(document.querySelectorAll('tr.file-row'));
    var selectedFiles = [];
    var lastIndex     = null;
    var bulkDeleteBtn = document.getElementById('bulk-delete-btn');

    function sameFile(a, b) {
        return a.name === b.name && (a.path || '') === (b.path || '');
    }

    function isSelected(name, path) {
        return selectedFiles.some(function(s) {
            return sameFile(s, { name: name, path: path });
        });
    }

    function updateSelectionVisual() {
        fileRows.forEach(function(row) {
            var name = row.getAttribute('data-name');
            var path = row.getAttribute('data-path') || '';
            var sel  = isSelected(name, path);
            if (sel) {
                row.classList.add('selected');
            } else {
                row.classList.remove('selected');
            }
        });

        if (bulkDeleteBtn) {
            bulkDeleteBtn.style.display = selectedFiles.length > 0 ? 'inline-block' : 'none';
        }
    }

    function getRowIndex(row) {
        var idxAttr = row.getAttribute('data-index');
        if (idxAttr == null) return null;
        var idx = parseInt(idxAttr, 10);
        return isNaN(idx) ? null : idx;
    }

    function handleRowClick(row, e) {
        // Ignore clicks on delete button / form
        if (e.target.closest('form')) {
            return;
        }

        var name = row.getAttribute('data-name');
        var path = row.getAttribute('data-path') || '';
        var idx  = getRowIndex(row);
        if (idx === null) idx = 0;

        if (e.shiftKey && lastIndex !== null && fileRows.length > 0) {
            // Range selection
            var start = Math.min(lastIndex, idx);
            var end   = Math.max(lastIndex, idx);
            selectedFiles = [];
            for (var i = start; i <= end; i++) {
                var r    = fileRows[i];
                if (!r) continue;
                var n    = r.getAttribute('data-name');
                var p    = r.getAttribute('data-path') || '';
                selectedFiles.push({ name: n, path: p });
            }
        } else if (e.ctrlKey || e.metaKey) {
            // Toggle selection
            var existingIdx = selectedFiles.findIndex(function(s) {
                return sameFile(s, { name: name, path: path });
            });
            if (existingIdx >= 0) {
                selectedFiles.splice(existingIdx, 1);
            } else {
                selectedFiles.push({ name: name, path: path });
            }
            lastIndex = idx;
        } else {
            // Single selection
            selectedFiles = [{ name: name, path: path }];
            lastIndex     = idx;
        }

        updateSelectionVisual();
    }

    fileRows.forEach(function(row) {
        row.addEventListener('click', function(e) {
            handleRowClick(row, e);
        });
    });

    var closeBtn = document.querySelector('.rdv-preview-close');
    var backdrop = document.getElementById('rdv-preview-backdrop');
    var navPrev  = document.querySelector('.rdv-preview-nav.prev');
    var navNext  = document.querySelector('.rdv-preview-nav.next');

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    if (backdrop) {
        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) {
                closeModal();
            }
        });
    }
    if (navPrev) {
        navPrev.addEventListener('click', function(e) {
            e.stopPropagation();
            showNext(-1);
        });
    }
    if (navNext) {
        navNext.addEventListener('click', function(e) {
            e.stopPropagation();
            showNext(1);
        });
    }

    document.addEventListener('keydown', function(e) {
        if (previewIndex === -1) return;
        if (e.key === 'Escape') {
            closeModal();
        } else if (e.key === 'ArrowRight') {
            showNext(1);
        } else if (e.key === 'ArrowLeft') {
            showNext(-1);
        }
    });

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            if (!selectedFiles.length) return;

            var fromPath = selectedFiles[0].path || '';
            var names    = selectedFiles.map(function(s) { return s.name; });

            if (!confirm('Delete ' + names.length + ' selected item(s)?')) {
                return;
            }

            var form     = document.getElementById('bulk-delete-form');
            var itemsEl  = document.getElementById('bulk-delete-items');
            var fromEl   = document.getElementById('bulk-delete-from');
            if (!form || !itemsEl || !fromEl) return;

            itemsEl.value = JSON.stringify(names);
            fromEl.value  = fromPath;

            form.submit();
        });
    }

    // ----- Internal drag-to-move (single or multi) -----
    var draggedSelection = [];
    var draggedFromPath  = null;

    fileRows.forEach(function(row) {
        var name = row.getAttribute('data-name');
        var path = row.getAttribute('data-path') || '';
        row.setAttribute('draggable', 'true');

        row.addEventListener('dragstart', function(e) {
            // If this row is not in the current selection, treat it as a single selection
            if (!isSelected(name, path)) {
                selectedFiles = [{ name: name, path: path }];
                lastIndex     = getRowIndex(row);
                updateSelectionVisual();
            }

            draggedSelection = selectedFiles.slice();
            draggedFromPath  = path;

            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', name);
            }
            hideMenu();
        });

        row.addEventListener('dragend', function() {
            draggedSelection = [];
            draggedFromPath  = null;
        });
    });

    var folderRows = document.querySelectorAll('tr.folder-row');
    folderRows.forEach(function(row) {
        var targetPath = row.getAttribute('data-path') || '';

        row.addEventListener('dragover', function(e) {
            if (!draggedSelection.length) return;
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'move';
            }
        });

        row.addEventListener('drop', function(e) {
            if (!draggedSelection.length) return;
            e.preventDefault();

            // All selected files assumed from same path in this UI
            var fromPath = draggedFromPath || '';

            if (draggedSelection.length === 1) {
                // Single move
                var single = draggedSelection[0];
                var form   = document.getElementById('move-form');
                var itemEl = document.getElementById('move-item');
                var fromEl = document.getElementById('move-from');
                var toEl   = document.getElementById('move-to');
                if (!form || !itemEl || !fromEl || !toEl) return;

                itemEl.value = single.name;
                fromEl.value = fromPath;
                toEl.value   = targetPath || '';

                form.submit();
            } else {
                // Multi-move
                var names   = draggedSelection.map(function(s) { return s.name; });
                var bForm   = document.getElementById('bulk-move-form');
                var itemsEl = document.getElementById('bulk-move-items');
                var fromEl2 = document.getElementById('bulk-move-from');
                var toEl2   = document.getElementById('bulk-move-to');
                if (!bForm || !itemsEl || !fromEl2 || !toEl2) return;

                itemsEl.value = JSON.stringify(names);
                fromEl2.value = fromPath;
                toEl2.value   = targetPath || '';

                bForm.submit();
            }
        });
    });
})();
</script>

</body>
</html>
