<?php
declare(strict_types=1);

// public/install.php

$configFile       = __DIR__ . '/../config/config.php';
$configDistFile   = __DIR__ . '/../config/config.dist.php';
$sabredavSqlFile  = __DIR__ . '/../config/sabredav.mysql.sql';
$rounddavSqlFile  = __DIR__ . '/../config/rounddav.mysql.sql';

// If config already exists, bail out to the main endpoint.
if (file_exists($configFile)) {
    header('Location: index.php');
    exit;
}

// Simple HTML escaper
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost       = trim((string)($_POST['db_host'] ?? ''));
    $dbName       = trim((string)($_POST['db_name'] ?? ''));
    $dbUser       = trim((string)($_POST['db_user'] ?? ''));
    $dbPass       = (string)($_POST['db_pass'] ?? '');
    $baseUri      = trim((string)($_POST['base_uri'] ?? ''));
    $adminUser    = trim((string)($_POST['admin_user'] ?? ''));
    $adminEmail   = trim((string)($_POST['admin_email'] ?? ''));
    $adminPass    = (string)($_POST['admin_pass'] ?? '');
    $filesRoot    = trim((string)($_POST['files_root'] ?? ''));
    $filesEnable  = isset($_POST['files_enable']) && $_POST['files_enable'] === '1';
    $sharedSecret = trim((string)($_POST['shared_secret'] ?? ''));
    $ssoSecret    = trim((string)($_POST['sso_secret'] ?? ''));

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Database host, name and user are required.';
    }

    if ($adminUser === '') {
        $adminUser = 'admin';
    }
    if ($adminPass === '') {
        $errors[] = 'Admin password is required.';
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email must be a valid address.';
    }

    if (!$errors) {
        // Build DSN and attempt DB connection
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);

        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }

        if (!$errors) {
            // 1) Apply SabreDAV core schema
            try {
                $sql = @file_get_contents($sabredavSqlFile);
                if ($sql === false) {
                    throw new RuntimeException('Failed to read ' . $sabredavSqlFile);
                }
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $errors[] = 'Error applying SabreDAV schema: ' . $e->getMessage();
            }

            // 2) Apply RoundDAV schema
            if (!$errors) {
                try {
                    $sql = @file_get_contents($rounddavSqlFile);
                    if ($sql === false) {
                        throw new RuntimeException('Failed to read ' . $rounddavSqlFile);
                    }
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    $errors[] = 'Error applying RoundDAV schema: ' . $e->getMessage();
                }
            }

            // 3) Generate config.php from config.dist.php
            if (!$errors) {
                $config = require $configDistFile;
                if (!is_array($config)) {
                    $errors[] = 'config.dist.php did not return an array.';
                } else {
                    // Database block
                    $config['database']['dsn']      = $dsn;
                    $config['database']['user']     = $dbUser;
                    $config['database']['password'] = $dbPass;
                    // Ensure PDO errors are set to exception mode
                    if (!isset($config['database']['options']) || !is_array($config['database']['options'])) {
                        $config['database']['options'] = [];
                    }
                    $config['database']['options'][PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

                    // Base URI: normalize if provided
                    if ($baseUri !== '') {
                        if (substr($baseUri, -1) !== '/') {
                            $baseUri .= '/';
                        }
                        $config['base_uri'] = $baseUri;
                    }

                    // Admin credentials: hash the password once and store
                    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                    if (!isset($config['admin']) || !is_array($config['admin'])) {
                        $config['admin'] = [];
                    }
                    $config['admin']['username']      = $adminUser;
                    $config['admin']['password_hash'] = $hash;
                    $config['admin']['email'] = $adminEmail;
                    $config['admin']['password'] = $adminEmail;

                    // Optional files root override / enabling
                    if (!isset($config['files']) || !is_array($config['files'])) {
                        $config['files'] = [];
                    }
                    if ($filesRoot !== '') {
                        $config['files']['root'] = $filesRoot;
                    }
                    if ($filesEnable) {
                        $config['files']['enabled'] = true;
                    }

                    // Optional provisioning shared_secret override
                    if (!isset($config['provision']) || !is_array($config['provision'])) {
                        $config['provision'] = [];
                    }
                    if ($sharedSecret !== '') {
                        $config['provision']['shared_secret'] = $sharedSecret;
                    }
                    // If left empty, we keep whatever is in config.dist.php (e.g. change_me_provision)

                    // Optional SSO shared secret override
                    if (!isset($config['sso']) || !is_array($config['sso'])) {
                        $config['sso'] = [];
                    }
                    if ($ssoSecret !== '') {
                        $config['sso']['secret'] = $ssoSecret;
                    }

                    $export = "<?php\nreturn " . var_export($config, true) . ";\n";

                    if (file_put_contents($configFile, $export) === false) {
                        $errors[] = 'Failed to write config.php. Check file permissions.';
                    } else {
                        $done = true;
                    }
                }
            }
        }
    }
}

if ($done) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>RoundDAV Installer</title>
        <style>
            :root {
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                color-scheme: light dark;
            }
            body {
                margin: 0;
                padding: 0;
                background: #f3f4f6;
            }
            .wrap {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .card {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
                padding: 2.5rem 3rem;
                max-width: 640px;
                width: 100%;
                text-align: center;
            }
            .logo {
                height: 56px;
                margin-bottom: 1.5rem;
            }
            h1 {
                font-size: 1.75rem;
                margin: 0 0 0.5rem;
                color: #111827;
            }
            p {
                margin: 0.5rem 0;
                color: #4b5563;
            }
            a.button {
                display: inline-block;
                margin-top: 1.75rem;
                padding: 0.75rem 1.4rem;
                border-radius: 999px;
                text-decoration: none;
                background: #2563eb;
                color: #ffffff;
                font-weight: 600;
            }
            a.button:hover {
                background: #1d4ed8;
            }
        </style>
    </head>
    <body>
    <div class="wrap">
        <div class="card">
            <img class="logo" src="assets/logo-rounddav.svg" alt="RoundDAV logo">
            <h1>RoundDAV Installed</h1>
            <p>Installation completed successfully.</p>
            <p>You can now launch the DAV endpoint or open the admin console.</p>
            <p>
                <a class="button" href="index.php">Launch RoundDAV</a>
            </p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>RoundDAV Installer</title>
    <style>
        :root {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color-scheme: light;
        }
        body {
            margin: 0;
            padding: 0;
            background: #f3f4f6;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            padding: 2.5rem 3rem;
            max-width: 760px;
            width: 100%;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        .logo {
            height: 48px;
        }
        h1 {
            font-size: 1.6rem;
            margin: 0;
            color: #111827;
        }
        .subtitle {
            margin: 0.25rem 0 0;
            color: #6b7280;
            font-size: 0.95rem;
        }
        .section-title {
            margin: 1.5rem 0 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
        }
        form {
            margin-top: 0.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.9rem;
            font-size: 0.9rem;
            color: #374151;
        }
        label span {
            display: inline-block;
            min-width: 160px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            max-width: 340px;
            padding: 0.5rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.2);
        }
        .hint {
            display: block;
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.2rem;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        .checkbox-row input[type="checkbox"] {
            width: auto;
        }
        .errors {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 0.75rem 0.9rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .errors ul {
            margin: 0.25rem 0 0;
            padding-left: 1.1rem;
        }
        .actions {
            margin-top: 1.5rem;
        }
        button[type="submit"] {
            padding: 0.7rem 1.3rem;
            border-radius: 999px;
            border: none;
            background: #2563eb;
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
        }
        button[type="submit"]:hover {
            background: #1d4ed8;
        }
        footer {
            margin-top: 1.75rem;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        code {
            background: #f3f4f6;
            padding: 0.1rem 0.3rem;
            border-radius: 4px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <header>
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <img class="logo" src="assets/logo-rounddav.svg" alt="RoundDAV logo">
                <div>
                    <h1>RoundDAV Installation</h1>
                    <p class="subtitle">MySQL-backed CalDAV/CardDAV service with Roundcube provisioning.</p>
                </div>
            </div>
        </header>

        <?php if ($errors): ?>
            <div class="errors">
                <strong>We hit a snag:</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="section-title">Database connection</div>

            <label>
                <span>MySQL host</span>
                <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>">
            </label>

            <label>
                <span>Database name</span>
                <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>">
                <span class="hint">The installer will create tables in this database.</span>
            </label>

            <label>
                <span>Database user</span>
                <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>">
            </label>

            <label>
                <span>Database password</span>
                <input type="password" name="db_pass" value="">
            </label>

            <div class="section-title">Admin console</div>

            <label>
                <span>Admin username</span>
                <input type="text" name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>">
                <span class="hint">Used to log into the /admin interface.</span>
            </label>

            <label>
                <span>Admin email</span>
                <input type="text" name="admin_email" value="<?= h($_POST['admin_email'] ?? '') ?>">
                <span class="hint">Optional. Stored in config.php for reference.</span>
            </label>

            <label>
                <span>Admin password</span>
                <input type="password" name="admin_pass" value="">
                <span class="hint">Required. The installer will store a salted hash in config.php.</span>
            </label>

            <div class="section-title">Provisioning (Roundcube)</div>

            <label>
                <span>Shared secret</span>
                <input type="text" name="shared_secret" value="<?= h($_POST['shared_secret'] ?? '') ?>">
                <span class="hint">Used by the Roundcube <code>rounddav_provision</code> plugin as the X-RoundDAV-Token secret. Leave empty to keep the default from config.dist.php.</span>
            </label>

            <div class="section-title">Single sign-on (Roundcube)</div>

            <label>
                <span>SSO shared secret</span>
                <input type="text" name="sso_secret" value="<?= h($_POST['sso_secret'] ?? '') ?>">
                <span class="hint">Matches <code>rounddav_sso_secret</code> in the Roundcube plugin. Leave empty to keep the default.</span>
            </label>

            <div class="section-title">Optional</div>

            <label>
                <span>Base URI</span>
                <input type="text" name="base_uri" value="<?= h($_POST['base_uri'] ?? '') ?>">
                <span class="hint">Leave empty to autodetect (useful if RoundDAV lives in a subfolder).</span>
            </label>

            <label>
                <span>Files root</span>
                <input type="text" name="files_root" value="<?= h($_POST['files_root'] ?? '') ?>">
                <span class="hint">Optional. Full filesystem path for WebDAV file storage (outside web root).</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="files_enable" value="1" <?= !empty($_POST['files_enable']) ? 'checked' : '' ?>>
                <span>Enable WebDAV file storage (/files collection) now</span>
            </label>

            <div class="actions">
                <button type="submit">Install RoundDAV</button>
            </div>

            <footer>
                Installer will write <code>config/config.php</code> and apply the SabreDAV and RoundDAV schemas.
            </footer>
        </form>
    </div>
</div>
</body>
</html>
