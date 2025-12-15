<?php
// src/Bookmarks/BookmarkService.php

namespace RoundDAV\Bookmarks;

use RuntimeException;

/**
 * Core bookmark domain logic.
 */
class BookmarkService
{
    public const LOCAL_DOMAIN = '__local__';

    private BookmarkRepository $repo;
    private array $config;
    private FaviconFetcher $faviconFetcher;
    private int $maxInlineIconBytes;

    public function __construct(BookmarkRepository $repo, array $config = [])
    {
        $this->repo    = $repo;
        $this->config  = $config;

        $bookmarkCfg   = $config['bookmarks'] ?? [];
        $timeout       = (int) ($bookmarkCfg['favicon_timeout'] ?? 4);
        $maxBytes      = (int) ($bookmarkCfg['max_favicon_bytes'] ?? 24576);
        $inlineMax     = (int) ($bookmarkCfg['max_inline_icon_bytes'] ?? 8192);

        $this->faviconFetcher   = new FaviconFetcher($timeout, $maxBytes);
        $this->maxInlineIconBytes = max(1024, min($maxBytes, $inlineMax));
    }

    public function listForUser(string $username, bool $includeShared = true, array $filters = []): array
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $filterSet = $this->normalizeFilters($filters);
        $visibilityFilter = $filterSet['visibility'] ?? 'all';
        $folderVisibility = $filterSet['folder_visibility'] ?? null;
        $filtersForPrivate = $filterSet;
        unset($filtersForPrivate['visibility'], $filtersForPrivate['folder_visibility']);
        $filtersForShared = $filtersForPrivate;

        if ($folderVisibility === 'shared') {
            unset($filtersForPrivate['folder_id']);
        } elseif ($folderVisibility === 'private') {
            unset($filtersForShared['folder_id']);
        }

        $domainSettings = $this->resolveDomainSettings($domain);
        $result = [
            'user'            => $userRow['username'],
            'domain'          => $domain,
            'shared_enabled'  => (bool) $domainSettings['shared_enabled'],
            'shared_label'    => $domainSettings['shared_label'] ?? 'Shared',
            'folders'         => [
                'private' => [],
                'shared'  => [],
            ],
            'bookmarks'       => [
                'private' => [],
                'shared'  => [],
            ],
        ];

        $result['folders']['private'] = $this->formatFolders($this->repo->listFolders($username, $domain, 'private'));
        $result['bookmarks']['private'] = [];
        if ($visibilityFilter !== 'shared') {
            $privateRows = $this->repo->listBookmarks($username, $domain, 'private', $filtersForPrivate);
            $result['bookmarks']['private'] = $this->formatBookmarks($privateRows);
        }

        if ($includeShared && $domainSettings['shared_enabled']) {
            $result['folders']['shared'] = $this->formatFolders($this->repo->listFolders(null, $domain, 'shared'));
            $result['bookmarks']['shared'] = [];
            if ($visibilityFilter !== 'private') {
                $sharedRows = $this->repo->listSharedBookmarksForUser($userRow['username'], $domain, $filtersForShared);
                $shareMap = $sharedRows ? $this->repo->getBookmarkShares(array_column($sharedRows, 'id')) : [];
                $result['bookmarks']['shared'] = $this->formatBookmarks($sharedRows, $shareMap);
            }
        } else {
            $result['folders']['shared'] = [];
            $result['bookmarks']['shared'] = [];
        }

        return $result;
    }

    public function metaForUser(string $username): array
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $settings = $this->resolveDomainSettings($domain);

        return [
            'user'            => $userRow['username'],
            'domain'          => $domain,
            'shared_enabled'  => (bool) $settings['shared_enabled'],
            'shared_label'    => $settings['shared_label'] ?? 'Shared Bookmarks',
        ];
    }

    public function activityForUser(string $username, int $limit = 40): array
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);

        $rows = $this->repo->listEventsForUser($userRow['username'], $domain, $limit);
        $events = [];
        foreach ($rows as $row) {
            if (!$this->eventVisibleToUser($row, $userRow['username'], $domain)) {
                continue;
            }
            $events[] = $this->formatEvent($row);
        }

        return $events;
    }

    public function createFolder(string $username, array $payload): array
    {
        $this->assertEnabled();
        $userRow  = $this->requireActiveUser($username);
        $domain   = $this->extractDomain($userRow['username']);
        $name     = trim((string) ($payload['name'] ?? ''));
        $visibility = $this->sanitizeVisibility($payload['visibility'] ?? 'private');
        $domainSettings = $this->resolveDomainSettings($domain);

        if ($name === '') {
            throw new RuntimeException('Folder name is required.');
        }

        if ($visibility === 'shared') {
            $settings = $this->resolveDomainSettings($domain);
            if (!$settings['shared_enabled']) {
                throw new RuntimeException('Shared bookmarks are disabled for this domain.');
            }
        }

        $parentId = $payload['parent_id'] ?? null;
        if ($parentId !== null) {
            $parentId = $this->validateFolderContext((int) $parentId, $username, $domain, $visibility);
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'owner_username' => $visibility === 'shared' ? null : $userRow['username'],
            'owner_domain'   => $domain,
            'visibility'     => $visibility,
            'name'           => $name,
            'parent_id'      => $parentId,
            'sort_order'     => (int) ($payload['sort_order'] ?? 0),
            'created_by'     => $userRow['username'],
            'updated_by'     => $userRow['username'],
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $id   = $this->repo->insertFolder($data);
        $row  = $this->repo->getFolder($id);

        return $this->formatFolder($row);
    }

    public function updateFolder(string $username, int $folderId, array $payload): array
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $folder  = $this->repo->getFolder($folderId);

        if (!$folder) {
            throw new RuntimeException('Folder not found.');
        }

        $this->assertFolderAccess($folder, $userRow['username'], $domain);

        $name = trim((string) ($payload['name'] ?? $folder['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Folder name cannot be empty.');
        }

        $parentId = array_key_exists('parent_id', $payload) ? $payload['parent_id'] : $folder['parent_id'];
        if ($parentId !== null) {
            $parentId = $this->validateFolderContext((int) $parentId, $userRow['username'], $domain, $folder['visibility'], $folderId);
        }

        $now = date('Y-m-d H:i:s');
        $this->repo->updateFolder($folderId, [
            'name'       => $name,
            'parent_id'  => $parentId,
            'sort_order' => (int) ($payload['sort_order'] ?? $folder['sort_order'] ?? 0),
            'updated_by' => $userRow['username'],
            'updated_at' => $now,
        ]);

        $row = $this->repo->getFolder($folderId);
        return $this->formatFolder($row);
    }

    public function deleteFolder(string $username, int $folderId): void
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $folder  = $this->repo->getFolder($folderId);

        if (!$folder) {
            throw new RuntimeException('Folder not found.');
        }

        $this->assertFolderAccess($folder, $userRow['username'], $domain);

        if ($this->repo->countChildFolders($folderId) > 0) {
            throw new RuntimeException('Folder contains subfolders. Remove them first.');
        }

        $this->repo->deleteFolder($folderId);
    }

    public function createBookmark(string $username, array $payload): array
    {
        $this->assertEnabled();
        $userRow    = $this->requireActiveUser($username);
        $domain     = $this->extractDomain($userRow['username']);

        $visibility = $this->sanitizeVisibility($payload['visibility'] ?? 'private');
        $url        = trim((string) ($payload['url'] ?? ''));
        $title      = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $favorite    = !empty($payload['favorite']);
        $tagsInput   = $payload['tags'] ?? [];

        if ($url === '') {
            throw new RuntimeException('URL is required.');
        }
        if (!preg_match('~^https?://~i', $url)) {
            throw new RuntimeException('Only http:// and https:// URLs are allowed.');
        }
        if ($title === '') {
            $title = $url;
        }

        $folderId = $payload['folder_id'] ?? null;
        if ($folderId !== null) {
            $folderId = $this->validateFolderContext((int) $folderId, $userRow['username'], $domain, $visibility);
        }

        $domainSettings = $this->resolveDomainSettings($domain);
        $this->debug('createBookmark user=' . $username . ' domain=' . $domain . ' visibility=' . $visibility
            . ' settings=' . json_encode($domainSettings));

        $shareScope = 'domain';
        $shareRows  = [];
        if ($visibility === 'shared') {
            if (!$domainSettings['shared_enabled']) {
                $this->debug('shared disabled branch triggered for domain=' . $domain);
                throw new RuntimeException('Shared bookmarks are disabled for this domain.');
            }
            $this->assertSharedLimit($domain, $domainSettings);
            [$shareScope, $shareRows] = $this->resolveShareConfig($payload, $domain, 'domain');
        } else {
            $this->assertPrivateLimit($userRow['username'], $domainSettings);
        }

        $now     = date('Y-m-d H:i:s');
        $favicon = $this->fetchFavicon($url);

        $data = [
            'owner_username'    => $visibility === 'shared' ? null : $userRow['username'],
            'owner_domain'      => $domain,
            'visibility'        => $visibility,
            'share_scope'       => $visibility === 'shared' ? $shareScope : 'domain',
            'folder_id'         => $folderId,
            'title'             => $title,
            'url'               => $url,
            'description'       => $description === '' ? null : $description,
            'tags'              => $this->normalizeTags($tagsInput),
            'is_favorite'       => $favorite ? 1 : 0,
            'created_by'        => $userRow['username'],
            'updated_by'        => $userRow['username'],
            'favicon_mime'      => $favicon['mime'] ?? null,
            'favicon_hash'      => $favicon['hash'] ?? null,
            'favicon_data'      => $favicon['data'] ?? null,
            'favicon_updated_at'=> $favicon ? $now : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $id  = $this->repo->insertBookmark($data);
        if ($visibility === 'shared') {
            $rows = $shareScope === 'custom'
                ? $this->decorateShareRows($shareRows, $userRow['username'], $now)
                : [];
            $this->repo->replaceBookmarkShares($id, $rows);
        } else {
            $this->repo->replaceBookmarkShares($id, []);
        }
        $shareSummary = $this->summarizeShares($shareScope, $shareRows);

        $this->logBookmarkEvent('bookmark.create', [
            'bookmark_id'    => $id,
            'owner_username' => $data['owner_username'],
            'owner_domain'   => $domain,
            'visibility'     => $visibility,
            'share_scope'    => $visibility === 'shared' ? $shareScope : 'private',
            'actor'          => $userRow['username'],
            'details'        => [
                'title'    => $title,
                'url'      => $url,
                'favorite' => $favorite,
                'tags'     => $this->decodeTags($data['tags']),
                'shares'   => $shareSummary,
            ],
        ]);

        $row = $this->repo->getBookmark($id);

        return $this->formatBookmark($row);
    }

    public function updateBookmark(string $username, int $bookmarkId, array $payload): array
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $bookmark = $this->repo->getBookmark($bookmarkId);

        if (!$bookmark) {
            throw new RuntimeException('Bookmark not found.');
        }

        $this->assertBookmarkAccess($bookmark, $userRow['username'], $domain);

        $title = trim((string) ($payload['title'] ?? $bookmark['title'] ?? ''));
        $url   = trim((string) ($payload['url'] ?? $bookmark['url'] ?? ''));
        $description = array_key_exists('description', $payload)
            ? trim((string) $payload['description'])
            : (string) $bookmark['description'];

        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            throw new RuntimeException('Only http:// and https:// URLs are allowed.');
        }
        if ($title === '') {
            $title = $url;
        }

        $favorite = array_key_exists('favorite', $payload)
            ? (!empty($payload['favorite']))
            : ((int) $bookmark['is_favorite'] === 1);

        if (array_key_exists('folder_id', $payload)) {
            $folderId = $payload['folder_id'];
            if ($folderId !== null) {
                $folderId = $this->validateFolderContext(
                    (int) $folderId,
                    $userRow['username'],
                    $domain,
                    $bookmark['visibility']
                );
            }
        } else {
            $folderId = $bookmark['folder_id'] !== null ? (int) $bookmark['folder_id'] : null;
        }

        $tags = array_key_exists('tags', $payload)
            ? $this->normalizeTags($payload['tags'])
            : $bookmark['tags'];

        $shareScope = $bookmark['share_scope'] ?? 'domain';
        $shareRows  = null;
        if ($bookmark['visibility'] === 'shared' && $this->shouldUpdateShareConfig($payload)) {
            [$shareScope, $shareRows] = $this->resolveShareConfig($payload, $domain, $shareScope);
        }

        $favicon = null;
        if (!empty($payload['refresh_icon'])) {
            $favicon = $this->fetchFavicon($url);
        }

        $now = date('Y-m-d H:i:s');

        $updateData = [
            'title'             => $title,
            'url'               => $url,
            'description'       => $description === '' ? null : $description,
            'tags'              => $tags,
            'folder_id'         => $folderId,
            'is_favorite'       => $favorite ? 1 : 0,
            'share_scope'       => $bookmark['visibility'] === 'shared' ? $shareScope : 'domain',
            'updated_by'        => $userRow['username'],
            'updated_at'        => $now,
            'favicon_mime'      => $favicon['mime'] ?? $bookmark['favicon_mime'],
            'favicon_hash'      => $favicon['hash'] ?? $bookmark['favicon_hash'],
            'favicon_data'      => $favicon['data'] ?? $bookmark['favicon_data'],
            'favicon_updated_at'=> $favicon ? $now : $bookmark['favicon_updated_at'],
        ];

        $shareSummary = [];
        if ($bookmark['visibility'] === 'shared' && $shareScope === 'custom') {
            $shareSummary = $this->summarizeShares($shareScope, $shareRows ?? [], $shareRows === null ? $bookmarkId : null);
        }

        $this->repo->updateBookmark($bookmarkId, $updateData);
        if ($bookmark['visibility'] === 'shared' && $shareRows !== null) {
            $rows = $shareScope === 'custom'
                ? $this->decorateShareRows($shareRows, $userRow['username'], $now)
                : [];
            $this->repo->replaceBookmarkShares($bookmarkId, $rows);
        }
        $this->logBookmarkEvent('bookmark.update', [
            'bookmark_id'    => $bookmarkId,
            'owner_username' => $bookmark['owner_username'],
            'owner_domain'   => $bookmark['owner_domain'],
            'visibility'     => $bookmark['visibility'],
            'share_scope'    => $bookmark['visibility'] === 'shared' ? $shareScope : 'private',
            'actor'          => $userRow['username'],
            'details'        => [
                'title'    => $title,
                'url'      => $url,
                'favorite' => $favorite,
                'tags'     => $this->decodeTags($tags),
                'shares'   => $shareSummary,
            ],
        ]);

        $row = $this->repo->getBookmark($bookmarkId);

        return $this->formatBookmark($row);
    }

    public function deleteBookmark(string $username, int $bookmarkId): void
    {
        $this->assertEnabled();
        $userRow = $this->requireActiveUser($username);
        $domain  = $this->extractDomain($userRow['username']);
        $bookmark = $this->repo->getBookmark($bookmarkId);

        if (!$bookmark) {
            throw new RuntimeException('Bookmark not found.');
        }

        $this->assertBookmarkAccess($bookmark, $userRow['username'], $domain);

        $shareSummary = [];
        if ($bookmark['visibility'] === 'shared') {
            $shareSummary = $this->summarizeShares($bookmark['share_scope'] ?? 'domain', [], $bookmarkId);
        }

        $this->repo->deleteBookmark($bookmarkId);
        $this->repo->replaceBookmarkShares($bookmarkId, []); // cleanup if cascade fails
        $this->logBookmarkEvent('bookmark.delete', [
            'bookmark_id'    => $bookmarkId,
            'owner_username' => $bookmark['owner_username'],
            'owner_domain'   => $bookmark['owner_domain'],
            'visibility'     => $bookmark['visibility'],
            'share_scope'    => $bookmark['visibility'] === 'shared'
                ? ($bookmark['share_scope'] ?? 'domain')
                : 'private',
            'actor'          => $userRow['username'],
            'details'        => [
                'title' => $bookmark['title'],
                'url'   => $bookmark['url'],
                'shares'=> $shareSummary,
            ],
        ]);
    }

    public function listDomainSettings(): array
    {
        $domains = $this->repo->listDomainSettings();
        foreach ($domains as &$domain) {
            $domain['shared_enabled'] = (int) $domain['shared_enabled'];
        }
        unset($domain);
        return $domains;
    }

    public function saveDomainSettings(string $domain, array $data): void
    {
        $domain = $this->normalizeDomain($domain);

        if ($domain !== self::LOCAL_DOMAIN) {
            // Remove legacy @domain rows so lookups stay consistent
            $this->repo->deleteDomainSettings('@' . $domain);
        }
        $now    = date('Y-m-d H:i:s');

        $label = trim((string) ($data['shared_label'] ?? ''));
        if ($label === '') {
            $label = 'Shared Bookmarks';
        }

        $payload = [
            'shared_enabled' => !empty($data['shared_enabled']) ? 1 : 0,
            'shared_label'   => $label,
            'max_private'    => isset($data['max_private']) ? (int) $data['max_private'] : null,
            'max_shared'     => isset($data['max_shared']) ? (int) $data['max_shared'] : null,
            'notes'          => trim((string) ($data['notes'] ?? '')) ?: null,
            'updated_at'     => $now,
        ];

        $existing = $this->repo->getDomainSettings($domain);
        if (!$existing) {
            $payload['created_at'] = $now;
        }

        $this->repo->upsertDomainSettings($domain, $payload);
    }

    public function deleteDomainSettings(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->repo->deleteDomainSettings($domain);
    }

    public function stats(): array
    {
        return $this->repo->bookmarkStats();
    }

    private function assertEnabled(): void
    {
        $enabled = $this->config['bookmarks']['enabled'] ?? true;
        if (!$enabled) {
            throw new RuntimeException('Bookmarks feature is disabled in config.');
        }
    }

    private function requireActiveUser(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('Username is required.');
        }

        $user = $this->repo->findUser($username);
        if (!$user) {
            throw new RuntimeException('User not provisioned in RoundDAV.');
        }
        if ((int) $user['active'] !== 1) {
            throw new RuntimeException('User is disabled in RoundDAV.');
        }

        return $user;
    }

    private function extractDomain(string $username): string
    {
        $username = strtolower(trim($username));
        $pos = strrpos($username, '@');
        if ($pos === false) {
            return self::LOCAL_DOMAIN;
        }

        $domain = substr($username, $pos + 1);
        if ($domain === '') {
            return self::LOCAL_DOMAIN;
        }

        return strtolower($domain);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = ltrim($domain, '@');
        if ($domain === '') {
            return self::LOCAL_DOMAIN;
        }
        return $domain;
    }

    private function resolveDomainSettings(string $domain): array
    {
        $defaults = [
            'shared_enabled' => (bool) ($this->config['bookmarks']['shared_default_enabled'] ?? true),
            'shared_label'   => $this->config['bookmarks']['shared_default_label'] ?? 'Shared Bookmarks',
            'max_private'    => $this->config['bookmarks']['max_private_per_user'] ?? null,
            'max_shared'     => $this->config['bookmarks']['max_shared_per_domain'] ?? null,
        ];

        $record = $this->repo->getDomainSettings($domain);
        if (!$record) {
            $record = $this->repo->getDomainSettings('@' . $domain);
        }
        if (!$record) {
            return $defaults;
        }

        return array_merge($defaults, $record);
    }

    private function sanitizeVisibility(string $visibility): string
    {
        $visibility = strtolower(trim($visibility));
        return $visibility === 'shared' ? 'shared' : 'private';
    }

    private function validateFolderContext(int $folderId, string $username, string $domain, string $visibility, ?int $currentFolderId = null): int
    {
        if ($folderId <= 0) {
            throw new RuntimeException('Invalid folder ID.');
        }

        if ($currentFolderId !== null && $folderId === $currentFolderId) {
            throw new RuntimeException('Folder cannot be its own parent.');
        }

        $folder = $this->repo->getFolder($folderId);
        if (!$folder) {
            throw new RuntimeException('Folder not found.');
        }

        $this->assertFolderAccess($folder, $username, $domain);

        if ($folder['visibility'] !== $visibility) {
            throw new RuntimeException('Folder visibility mismatch.');
        }

        return $folderId;
    }

    private function assertFolderAccess(array $folder, string $username, string $domain): void
    {
        if ($folder['visibility'] === 'shared') {
            if ($folder['owner_domain'] !== $domain) {
                throw new RuntimeException('Shared folder belongs to a different domain.');
            }
            return;
        }

        if ($folder['owner_username'] !== $username) {
            throw new RuntimeException('Folder belongs to a different user.');
        }
    }

    private function assertBookmarkAccess(array $bookmark, string $username, string $domain): void
    {
        if ($bookmark['visibility'] === 'shared') {
            if ($bookmark['owner_domain'] !== $domain) {
                throw new RuntimeException('Shared bookmark belongs to a different domain.');
            }
            return;
        }

        if ($bookmark['owner_username'] !== $username) {
            throw new RuntimeException('Bookmark belongs to a different user.');
        }
    }

    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (!empty($filters['search'])) {
            $normalized['search'] = trim((string) $filters['search']);
        }

        if (!empty($filters['favorite_only'])) {
            $normalized['favorite_only'] = true;
        }

        if (array_key_exists('folder_id', $filters) && $filters['folder_id'] !== '' && $filters['folder_id'] !== null) {
            $normalized['folder_id'] = (int) $filters['folder_id'];
        }

        if (!empty($filters['tags'])) {
            if (is_array($filters['tags'])) {
                $normalized['tags'] = $filters['tags'];
            } else {
                $normalized['tags'] = explode(',', (string) $filters['tags']);
            }
        }

        if (!empty($filters['folder_visibility'])) {
            $fv = strtolower(trim((string) $filters['folder_visibility']));
            if (in_array($fv, ['private', 'shared'], true)) {
                $normalized['folder_visibility'] = $fv;
            }
        }

        $visibility = strtolower(trim((string) ($filters['visibility'] ?? 'all')));
        if (!in_array($visibility, ['private', 'shared', 'all'], true)) {
            $visibility = 'all';
        }
        $normalized['visibility'] = $visibility;

        return $normalized;
    }

    private function normalizeTags($tags): ?string
    {
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        } elseif (is_array($tags)) {
            $tags = array_filter(array_map(function ($tag) {
                return trim((string) $tag);
            }, $tags));
        } else {
            $tags = [];
        }

        if (!$tags) {
            return null;
        }

        $tags = array_values(array_unique($tags));
        return json_encode($tags, JSON_UNESCAPED_UNICODE);
    }

    private function resolveShareConfig(array $payload, string $domain, string $defaultScope = 'domain'): array
    {
        $explicitMode = array_key_exists('share_mode', $payload);
        $mode = strtolower(trim((string) ($payload['share_mode'] ?? $defaultScope)));
        $hasTargets = !empty($payload['share_users']) || !empty($payload['share_domains']);

        if (!$explicitMode && !$hasTargets) {
            return [$defaultScope === 'custom' ? 'custom' : 'domain', []];
        }

        if ($mode !== 'custom' && $hasTargets) {
            $mode = 'custom';
        }

        if ($mode !== 'custom') {
            return ['domain', []];
        }

        $users   = $this->normalizeShareUsers($payload['share_users'] ?? []);
        $domains = $this->normalizeShareDomains($payload['share_domains'] ?? []);

        if (!$users && !$domains) {
            throw new RuntimeException('Specify at least one user or domain for custom sharing.');
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'share_type'   => 'user',
                'share_target' => $user,
            ];
        }
        foreach ($domains as $targetDomain) {
            $rows[] = [
                'share_type'   => 'domain',
                'share_target' => $targetDomain,
            ];
        }

        return ['custom', $rows];
    }

    private function shouldUpdateShareConfig(array $payload): bool
    {
        return array_key_exists('share_mode', $payload)
            || array_key_exists('share_users', $payload)
            || array_key_exists('share_domains', $payload);
    }

    private function normalizeShareUsers($input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\\s,;]+/', $input);
        } elseif (!is_array($input)) {
            $input = [];
        }

        $result = [];
        foreach ($input as $value) {
            $value = strtolower(trim((string) $value));
            if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $result[$value] = true;
        }
        return array_keys($result);
    }

    private function normalizeShareDomains($input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\\s,;]+/', $input);
        } elseif (!is_array($input)) {
            $input = [];
        }

        $result = [];
        foreach ($input as $value) {
            $raw = trim((string) $value);
            if ($raw === '') {
                continue;
            }
            $value = $this->normalizeDomain($raw);
            if ($value === '') {
                continue;
            }
            $result[$value] = true;
        }
        return array_keys($result);
    }

    private function decorateShareRows(array $rows, string $actor, ?string $timestamp = null): array
    {
        $ts = $timestamp ?? date('Y-m-d H:i:s');
        return array_map(function ($row) use ($actor, $ts) {
            return [
                'share_type'   => $row['share_type'],
                'share_target' => $row['share_target'],
                'created_by'   => $actor,
                'created_at'   => $ts,
            ];
        }, $rows);
    }

    private function summarizeShares(string $scope, array $rows = [], ?int $bookmarkId = null): array
    {
        if ($scope !== 'custom') {
            return [];
        }
        if (!$rows && $bookmarkId !== null) {
            $existing = $this->repo->getBookmarkShares([$bookmarkId]);
            $rows = $existing[$bookmarkId] ?? [];
        }
        $summary = [];
        foreach ($rows as $row) {
            $type = $row['share_type'] ?? $row['type'] ?? null;
            $target = $row['share_target'] ?? $row['target'] ?? null;
            if (!$type || !$target) {
                continue;
            }
            $summary[] = [
                'type'   => $type,
                'target' => strtolower((string) $target),
            ];
        }
        return $summary;
    }

    private function fetchFavicon(string $url): ?array
    {
        try {
            return $this->faviconFetcher->fetch($url);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decodeTags(?string $stored): array
    {
        if (!$stored) {
            return [];
        }
        $decoded = json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values($decoded);
    }

    private function formatFolders(array $rows): array
    {
        return array_map(fn($row) => $this->formatFolder($row), $rows);
    }

    private function formatFolder(?array $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'parent_id'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'visibility'  => $row['visibility'],
            'sort_order'  => (int) ($row['sort_order'] ?? 0),
            'updated_at'  => $row['updated_at'],
        ];
    }

    private function formatBookmarks(array $rows, array $shareMap = []): array
    {
        $items = [];
        foreach ($rows as $row) {
            $shares = $shareMap[$row['id']] ?? [];
            $items[] = $this->formatBookmark($row, $shares);
        }
        return $items;
    }

    private function formatBookmark(array $row, array $shares = []): array
    {
        $icon = null;
        if (!empty($row['favicon_data']) && strlen($row['favicon_data']) <= $this->maxInlineIconBytes) {
            $icon = [
                'mime' => $row['favicon_mime'] ?? 'image/x-icon',
                'data' => base64_encode($row['favicon_data']),
                'hash' => $row['favicon_hash'],
            ];
        }

        $tags = $this->decodeTags($row['tags'] ?? null);

        $shareScope = $row['visibility'] === 'shared'
            ? ($row['share_scope'] ?? 'domain')
            : 'private';

        return [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['title'],
            'url'         => (string) $row['url'],
            'description' => $row['description'] ?? '',
            'folder_id'   => $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            'visibility'  => $row['visibility'],
            'share_scope' => $shareScope,
            'shares'      => array_map(function ($share) {
                return [
                    'type'   => $share['type'],
                    'target' => $share['target'],
                ];
            }, $shares),
            'favorite'    => (int) $row['is_favorite'] === 1,
            'tags'        => $tags,
            'icon'        => $icon,
            'updated_at'  => $row['updated_at'],
        ];
    }

    private function assertPrivateLimit(string $username, ?array $domainSettings = null): void
    {
        $limit = $domainSettings['max_private'] ?? $this->config['bookmarks']['max_private_per_user'] ?? null;
        if (!$limit) {
            return;
        }

        $count = $this->repo->countPrivateBookmarks($username);
        if ($count >= $limit) {
            throw new RuntimeException('Private bookmark limit reached for this user.');
        }
    }

    private function assertSharedLimit(string $domain, array $settings): void
    {
        $limit = $settings['max_shared'] ?? $this->config['bookmarks']['max_shared_per_domain'] ?? null;
        if (!$limit) {
            return;
        }

        $count = $this->repo->countSharedBookmarks($domain);
        if ($count >= $limit) {
            throw new RuntimeException('Shared bookmark limit reached for this domain.');
        }
    }

    private function eventVisibleToUser(array $row, string $username, string $domain): bool
    {
        $bookmarkActive = !empty($row['active_bookmark_id']);
        if ($bookmarkActive) {
            return true;
        }

        $visibility = $row['visibility'] ?? 'private';
        if ($visibility === 'private') {
            return strtolower((string) ($row['owner_username'] ?? '')) === strtolower($username);
        }

        if (($row['share_scope'] ?? 'domain') === 'domain') {
            return strtolower((string) ($row['owner_domain'] ?? '')) === strtolower($domain);
        }

        $details = $row['details'] ?? [];
        if (!is_array($details) || empty($details['shares'])) {
            return false;
        }

        $user = strtolower($username);
        $dom  = strtolower($domain);
        foreach ($details['shares'] as $share) {
            $type = $share['type'] ?? '';
            $target = strtolower((string) ($share['target'] ?? ''));
            if ($type === 'user' && $target === $user) {
                return true;
            }
            if ($type === 'domain' && $target === $dom) {
                return true;
            }
        }

        return false;
    }

    private function logBookmarkEvent(string $action, array $context): void
    {
        try {
            $this->repo->insertEvent([
                'bookmark_id'    => $context['bookmark_id'] ?? null,
                'folder_id'      => $context['folder_id'] ?? null,
                'owner_username' => $context['owner_username'] ?? null,
                'owner_domain'   => $context['owner_domain'],
                'visibility'     => $context['visibility'],
                'share_scope'    => $context['share_scope'] ?? 'private',
                'actor'          => $context['actor'],
                'action'         => $action,
                'details'        => $context['details'] ?? null,
            ]);
        } catch (\Throwable $e) {
            if (!empty($this->config['debug'])) {
                $this->debug('Bookmark event log failed: ' . $e->getMessage());
            }
        }
    }

    private function formatEvent(array $row): array
    {
        $details = is_array($row['details']) ? $row['details'] : [];
        $title = $row['title'] ?? ($details['title'] ?? null);
        $url   = $row['url'] ?? ($details['url'] ?? null);

        return [
            'id'          => (int) $row['id'],
            'bookmark_id' => $row['bookmark_id'] !== null ? (int) $row['bookmark_id'] : null,
            'folder_id'   => $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            'action'      => $row['action'],
            'actor'       => $row['actor'],
            'visibility'  => $row['visibility'],
            'share_scope' => $row['share_scope'],
            'title'       => $title,
            'url'         => $url,
            'shares'      => $details['shares'] ?? [],
            'details'     => $details,
            'created_at'  => $row['created_at'],
        ];
    }

    private function debug(string $message): void
    {
        error_log('[RoundDAV bookmarks] ' . $message);
    }
}
