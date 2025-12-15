<?php
// src/Bootstrap.php

namespace RoundDAV;

use PDO;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Browser\Plugin as BrowserPlugin;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
use Sabre\DAV\PropertyStorage\Plugin as PropertyStoragePlugin;
use Sabre\DAV\PropertyStorage\Backend\PDO as PropertyBackendPDO;
use RoundDAV\Auth\RoundcubeBackend;
use RoundDAV\Dav\RateLimiter;
use RoundDAV\Dav\FilesRoot;

/**
 * Bootstrap factory for creating the SabreDAV server.
 */
class Bootstrap
{
    /**
     * Create and configure a SabreDAV server instance.
     *
     * @param string $baseDir Project base directory (one level above public/)
     * @param array  $config  RoundDAV configuration array
     *
     * @return DAV\Server
     */
    public static function createServer(string $baseDir, array $config): DAV\Server
    {
        if (empty($config['database']['dsn'])) {
            throw new \RuntimeException('Database DSN is not configured.');
        }

        // Build PDO connection
        $pdo = new PDO(
            $config['database']['dsn'],
            $config['database']['user'] ?? null,
            $config['database']['password'] ?? null,
            $config['database']['options'] ?? []
        );

        // Core SabreDAV PDO backends (using the standard sabredav.mysql.sql schema)
        $principalBackend = new DAVACL\PrincipalBackend\PDO($pdo);
        $caldavBackend    = new CalDAV\Backend\PDO($pdo);
        $carddavBackend   = new CardDAV\Backend\PDO($pdo);

        // Tree:
        //  - /principals
        //  - /calendars
        //  - /addressbooks
        //  - /files  (optional; filesystem WebDAV)
        $nodes = [
            new DAVACL\PrincipalCollection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $caldavBackend),
            new CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
        ];

        // Optional WebDAV file storage
        $filesCfg = $config['files'] ?? null;
        if (is_array($filesCfg) && !empty($filesCfg['enabled']) && !empty($filesCfg['root'])) {
            $rootPath = rtrim($filesCfg['root'], DIRECTORY_SEPARATOR);
            if (!is_dir($rootPath)) {
                @mkdir($rootPath, 0770, true);
            }
            if (is_dir($rootPath)) {
                $nodes[] = new FilesRoot($rootPath);
            }
        }

        $server = new DAV\Server($nodes);

        // Base URI: explicit in config, or auto-detected
        if (!empty($config['base_uri'])) {
            $server->setBaseUri(rtrim($config['base_uri'], '/') . '/');
        } else {
            $server->setBaseUri(self::detectBaseUri());
        }

        // Authentication: HTTP Basic backed by rounddav_users
        $authBackend = new RoundcubeBackend($pdo);
        $authPlugin  = new AuthPlugin($authBackend, 'RoundDAV');
        $server->addPlugin($authPlugin);

        // ACL plugin – required for CalDAV/CardDAV
        $aclPlugin = new DAVACL\Plugin();
        $server->addPlugin($aclPlugin);

        // CalDAV and CardDAV protocol plugins
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CardDAV\Plugin());


        // Property storage plugin for arbitrary WebDAV properties
        $propBackend = new PropertyBackendPDO($pdo);
        $propPlugin  = new PropertyStoragePlugin($propBackend);
        $server->addPlugin($propPlugin);

        // Sync plugin (for REPORT sync-token support)
        $syncPlugin = new SyncPlugin('synctoken');
        $server->addPlugin($syncPlugin);

        // Optional HTML browser (debug only)
        if (!empty($config['debug'])) {
            $server->addPlugin(new BrowserPlugin());
        }

        // Rate limiter stub – currently no-op, but wired for future use
        $server->addPlugin(new RateLimiter($pdo, $config));

        // TODO: Hook in global collections and sharing rules here via a custom plugin

        return $server;
    }

    protected static function detectBaseUri(): string
    {
        // Very basic auto-detect that works for subfolders, e.g. /rounddav/public/index.php
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        // We expect index.php under /public/
        $uri = rtrim(str_replace('index.php', '', $scriptName), '/');
        return $uri . '/';
    }
}
