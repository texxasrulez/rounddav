<?php
// src/Provision/ApiController.php

namespace RoundDAV\Provision;

use PDO;
use RuntimeException;
use RoundDAV\Bookmarks\BookmarkApi;

class ApiController
{
    private PDO $pdo;
    private array $config;
    private UserProvisioner $provisioner;
    private BookmarkApi $bookmarkApi;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo          = $pdo;
        $this->config       = $config;
        $this->provisioner  = new UserProvisioner($pdo, $config);
        $this->bookmarkApi  = new BookmarkApi($pdo, $config);
    }

    public function handle(): void
    {
        try {
            $route = $_GET['r'] ?? 'provision/user';

            if (strpos($route, 'bookmarks/') === 0) {
                $this->bookmarkApi->handle($route);
                return;
            }

            $this->assertMethod();
            $this->assertToken();

            $this->log('API request: route=' . $route . ' ip=' . ($this->getClientIp() ?? 'unknown'));

            switch ($route) {
                case 'provision/user':
                    $this->handleProvisionUser();
                    break;

                default:
                    $this->log('API error: unknown route "' . $route . '"');
                    http_response_code(404);
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'Unknown route: ' . $route,
                    ]);
                    break;
            }
        } catch (\Throwable $e) {
            $debug = $this->config['debug'] ?? false;

            $this->log('API exception: ' . $e->getMessage());

            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => $debug ? $e->getMessage() : 'Internal provisioning error',
            ]);
        }
    }
private function assertMethod(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode([
                'status'  => 'error',
                'message' => 'POST required',
            ]);
            exit;
        }
    }

    private function assertToken(): void
    {
        $provision = $this->config['provision'] ?? [];
        $expected  = $provision['shared_secret'] ?? null;

        if (empty($expected)) {
            throw new RuntimeException('Provisioning shared_secret not configured.');
        }

        $token = $_SERVER['HTTP_X_ROUNDDAV_TOKEN'] ?? '';

        if (!hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Invalid API token',
            ]);
            exit;
        }
    }

    private function handleProvisionUser(): void
    {
        $data = $this->getRequestData();

        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->log('Provision request rejected: missing username or password');
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Missing username or password',
            ]);
            return;
        }

        $displayName = $data['displayname'] ?? $username;
        $email       = $data['email'] ?? (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null);

        $extraCalendars    = isset($data['extra_calendars']) && is_array($data['extra_calendars']) ? $data['extra_calendars'] : [];
        $extraAddressbooks = isset($data['extra_addressbooks']) && is_array($data['extra_addressbooks']) ? $data['extra_addressbooks'] : [];

        $this->log(sprintf(
            'Provisioning user "%s" (email=%s)',
            $username,
            $email ?? 'null'
        ));

        $result = $this->provisioner->provisionUser(
            $username,
            $password,
            [
                'displayname'        => $displayName,
                'email'              => $email,
                'extra_calendars'    => $extraCalendars,
                'extra_addressbooks' => $extraAddressbooks,
            ]
        );

        $this->log(sprintf(
            'Provision OK for "%s" principal=%s id=%s',
            $username,
            $result['principal_uri'] ?? 'n/a',
            (string) ($result['principal_id'] ?? 'n/a')
        ));

        http_response_code(200);
        echo json_encode($result);
    }

    private function getRequestData(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        return [];
    }

    /**
     * Very small file logger for provisioning/debug messages.
     */
    private function log(string $message): void
    {
        $logCfg = $this->config['log'] ?? [];
        $enabled = $logCfg['enabled'] ?? false;
        if (!$enabled) {
            return;
        }

        $file = $logCfg['file'] ?? null;
        if (!is_string($file) || $file === '') {
            return;
        }

        $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);

        // Suppress errors: logging must never break the API itself.
        try {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function getClientIp(): ?string
    {
        $keys = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = (string) $_SERVER[$key];
                // If there is a comma-separated chain, take the first element.
                $parts = explode(',', $raw);
                return trim($parts[0]);
            }
        }

        return null;
    }

}
