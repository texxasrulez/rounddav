<?php
// src/Bookmarks/BookmarkApi.php

namespace RoundDAV\Bookmarks;

use PDO;
use RuntimeException;

/**
 * HTTP-facing bookmark API dispatcher.
 *
 * This class is invoked from public/api.php whenever the route starts
 * with "bookmarks/".
 */
class BookmarkApi
{
    private BookmarkService $service;
    private BookmarkRepository $repo;
    private array $config;
    private ?string $sharedSecret;

    public function __construct(PDO $pdo, array $config)
    {
        $this->repo    = new BookmarkRepository($pdo);
        $this->service = new BookmarkService($this->repo, $config);
        $this->config  = $config;

        $bookmarkSecret = (string) ($config['bookmarks']['shared_secret'] ?? '');
        if ($bookmarkSecret === '') {
            $bookmarkSecret = (string) ($config['provision']['shared_secret'] ?? '');
        }
        $this->sharedSecret = $bookmarkSecret !== '' ? $bookmarkSecret : null;
    }

    public function handle(string $route): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(['status' => 'error', 'message' => 'POST required'], 405);
            return;
        }

        try {
            $auth    = $this->resolveAuthContext();
            $payload = $this->getRequestData();

            $username = $auth['mode'] === 'trusted'
                ? trim((string) ($payload['username'] ?? ''))
                : $auth['username'];

            if ($username === '') {
                throw new RuntimeException('Username is required for this operation.');
            }

            switch ($route) {
                case 'bookmarks/list':
                    $includeShared = array_key_exists('include_shared', $payload)
                        ? (bool) $payload['include_shared']
                        : true;
                    $data = $this->service->listForUser($username, $includeShared);
                    $this->respond(['status' => 'ok', 'data' => $data]);
                    break;

                case 'bookmarks/meta':
                    $data = $this->service->metaForUser($username);
                    $this->respond(['status' => 'ok', 'data' => $data]);
                    break;

                case 'bookmarks/create':
                    $data = $this->service->createBookmark($username, $payload);
                    $this->respond(['status' => 'ok', 'bookmark' => $data]);
                    break;

                case 'bookmarks/update':
                    $bookmarkId = (int) ($payload['id'] ?? 0);
                    if ($bookmarkId <= 0) {
                        throw new RuntimeException('Bookmark ID is required.');
                    }
                    $data = $this->service->updateBookmark($username, $bookmarkId, $payload);
                    $this->respond(['status' => 'ok', 'bookmark' => $data]);
                    break;

                case 'bookmarks/delete':
                    $bookmarkId = (int) ($payload['id'] ?? 0);
                    if ($bookmarkId <= 0) {
                        throw new RuntimeException('Bookmark ID is required.');
                    }
                    $this->service->deleteBookmark($username, $bookmarkId);
                    $this->respond(['status' => 'ok']);
                    break;

                case 'bookmarks/folder/create':
                    $folder = $this->service->createFolder($username, $payload);
                    $this->respond(['status' => 'ok', 'folder' => $folder]);
                    break;

                case 'bookmarks/folder/update':
                    $folderId = (int) ($payload['id'] ?? 0);
                    if ($folderId <= 0) {
                        throw new RuntimeException('Folder ID is required.');
                    }
                    $folder = $this->service->updateFolder($username, $folderId, $payload);
                    $this->respond(['status' => 'ok', 'folder' => $folder]);
                    break;

                case 'bookmarks/folder/delete':
                    $folderId = (int) ($payload['id'] ?? 0);
                    if ($folderId <= 0) {
                        throw new RuntimeException('Folder ID is required.');
                    }
                    $this->service->deleteFolder($username, $folderId);
                    $this->respond(['status' => 'ok']);
                    break;

                default:
                    $this->respond(['status' => 'error', 'message' => 'Unknown bookmarks route'], 404);
                    break;
            }
        } catch (RuntimeException $e) {
            $this->respond(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $debug = !empty($this->config['debug']);
            $this->respond([
                'status'  => 'error',
                'message' => $debug ? $e->getMessage() : 'Bookmarks API error',
            ], 500);
        }
    }

    private function resolveAuthContext(): array
    {
        $token = $_SERVER['HTTP_X_ROUNDDAV_TOKEN'] ?? '';
        if ($token !== '' && $this->sharedSecret !== null && hash_equals($this->sharedSecret, $token)) {
            return ['mode' => 'trusted'];
        }

        $basic = $this->authenticateBasic();
        if ($basic) {
            return ['mode' => 'user', 'username' => $basic['username']];
        }

        if ($this->sharedSecret !== null) {
            throw new RuntimeException('Missing or invalid RoundDAV token.');
        }

        throw new RuntimeException('Authentication required.');
    }

    private function authenticateBasic(): ?array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($user === null || $pass === null) {
            return null;
        }

        $record = $this->repo->findUser($user);
        if (!$record || (int) $record['active'] !== 1) {
            throw new RuntimeException('Invalid credentials.');
        }

        if (empty($record['password_hash']) || !password_verify($pass, $record['password_hash'])) {
            throw new RuntimeException('Invalid credentials.');
        }

        return ['username' => $record['username']];
    }

    private function getRequestData(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
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

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}
