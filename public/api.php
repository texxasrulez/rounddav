<?php
declare(strict_types=1);

use RoundDAV\Provision\ApiController;
use PDO;
use PDOException;

// No BOM, no whitespace before this tag.
// This script is the RoundDAV provisioning API front controller.

header('Content-Type: application/json; charset=utf-8');

$baseDir = dirname(__DIR__);

// Autoload classes
$autoload = $baseDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Composer autoloader not found',
    ]);
    exit;
}
require $autoload;

// Load config
$configFile = $baseDir . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'RoundDAV config.php not found',
    ]);
    exit;
}

$config = require $configFile;

if (!is_array($config) || empty($config['database']['dsn'])) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid RoundDAV configuration (database settings missing)',
    ]);
    exit;
}

// Create PDO
$db = $config['database'];

$dsn      = $db['dsn']      ?? '';
$dbUser   = $db['user']     ?? '';
$dbPass   = $db['password'] ?? '';
$dbOpts   = $db['options']  ?? [];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $dbOpts);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed',
    ]);
    // Don’t leak DSN details in production
    exit;
}

// Hand off to API controller
$controller = new ApiController($pdo, $config);
$controller->handle();
