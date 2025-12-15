<?php
// public/index.php

declare(strict_types=1);

use RoundDAV\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$configFile = __DIR__ . '/../config/config.php';

if (!file_exists($configFile)) {
    // Redirect to installer if config doesn't exist
    header('Location: install.php');
    exit;
}

$config = require $configFile;

try {
    $server = Bootstrap::createServer(__DIR__ . '/..', $config);
    $server->exec();
} catch (Throwable $e) {
    // Very basic error handler to avoid white screen of death
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "RoundDAV fatal error:\n\n";
    echo $e->getMessage() . "\n";
    if (!empty($config['debug'])) {
        echo "\nTrace:\n" . $e->getTraceAsString();
    }
}
