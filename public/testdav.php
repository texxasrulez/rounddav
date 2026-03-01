<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $enabled = array_key_exists('enable_testdav', $config['endpoints'] ?? [])
        ? !empty($config['endpoints']['enable_testdav'])
        : true;
    if (!$enabled) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not Found\n";
        exit;
    }
}

header('Content-Type: text/plain');
echo "METHOD=" . $_SERVER['REQUEST_METHOD'] . "\n";
echo "URI=" . $_SERVER['REQUEST_URI'] . "\n";
