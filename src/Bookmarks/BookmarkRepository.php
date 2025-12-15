<?php
// src/Bookmarks/BookmarkRepository.php

namespace RoundDAV\Bookmarks;

use PDO;

/**
 * Low-level DB helper for bookmark storage.
 *
 * Keeps all SQL in one place so the service layer can focus on validation
 * and business rules.
 */
class BookmarkRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findUser(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, principal_uri, active, password_hash
             FROM rounddav_users
             WHERE username = :u
             LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getDomainSettings(string $domain): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM rounddav_bookmark_domains
             WHERE LOWER(TRIM(domain)) = LOWER(:d)
             LIMIT 1'
        );
        $stmt->execute([':d' => trim($domain)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function upsertDomainSettings(string $domain, array $data): void
    {
        $sql = 'INSERT INTO rounddav_bookmark_domains
                    (domain, shared_enabled, shared_label, max_private, max_shared, notes, created_at, updated_at)
                VALUES (:domain, :shared_enabled, :shared_label, :max_private, :max_shared, :notes, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    shared_enabled = VALUES(shared_enabled),
                    shared_label   = VALUES(shared_label),
                    max_private    = VALUES(max_private),
                    max_shared     = VALUES(max_shared),
                    notes          = VALUES(notes),
                    updated_at     = VALUES(updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':domain'         => $domain,
            ':shared_enabled' => (int) ($data['shared_enabled'] ?? 1),
            ':shared_label'   => $data['shared_label'] ?? null,
            ':max_private'    => $data['max_private'] ?? null,
            ':max_shared'     => $data['max_shared'] ?? null,
            ':notes'          => $data['notes'] ?? null,
            ':created_at'     => $data['created_at'] ?? date('Y-m-d H:i:s'),
            ':updated_at'     => $data['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteDomainSettings(string $domain): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rounddav_bookmark_domains WHERE LOWER(TRIM(domain)) = LOWER(:d)'
        );
        $stmt->execute([':d' => trim($domain)]);
    }

    public function listDomainSettings(): array
    {
        $stmt = $this->pdo->query(
            'SELECT *
             FROM rounddav_bookmark_domains
             ORDER BY domain ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listFolders(?string $ownerUsername, string $domain, string $visibility): array
    {
        $params = [
            ':visibility'   => $visibility,
            ':owner_domain' => $domain,
        ];

        $where = 'owner_domain = :owner_domain AND visibility = :visibility';

        if ($ownerUsername !== null) {
            $where .= ' AND owner_username = :owner_username';
            $params[':owner_username'] = $ownerUsername;
        } else {
            $where .= ' AND owner_username IS NULL';
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, owner_username, owner_domain, visibility, name, parent_id, sort_order,
                    created_by, updated_by, created_at, updated_at
             FROM rounddav_bookmark_folders
             WHERE {$where}
             ORDER BY sort_order ASC, name ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listBookmarks(?string $ownerUsername, string $domain, string $visibility, array $filters = []): array
    {
        $params = [
            ':visibility'   => $visibility,
            ':owner_domain' => $domain,
        ];

        $where = ['owner_domain = :owner_domain', 'visibility = :visibility'];

        if ($ownerUsername !== null) {
            $where[] = 'owner_username = :owner_username';
            $params[':owner_username'] = $ownerUsername;
        } else {
            $where[] = 'owner_username IS NULL';
        }

        $this->applyBookmarkFilters($where, $params, $filters, 'rounddav_bookmarks');

        $whereSql = implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT id, owner_username, owner_domain, visibility, folder_id, title, url,
                    description, tags, is_favorite, created_by, updated_by,
                    favicon_mime, favicon_hash, favicon_data, favicon_updated_at,
                    created_at, updated_at, share_scope
             FROM rounddav_bookmarks
             WHERE {$whereSql}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listSharedBookmarksForUser(string $username, string $domain, array $filters = []): array
    {
        $params = [
            ':username' => $username,
            ':domain'   => $domain,
        ];

        $where = [
            "visibility = 'shared'",
            '(' .
                "(share_scope = 'domain' AND owner_domain = :domain) OR " .
                "(share_scope = 'custom' AND (" .
                    "(s.share_type = 'user' AND s.share_target = :username) OR " .
                    "(s.share_type = 'domain' AND s.share_target = :domain)" .
                '))' .
            ')',
        ];

        $this->applyBookmarkFilters($where, $params, $filters, 'b');

        $whereSql = implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT b.id, b.owner_username, b.owner_domain, b.visibility, b.share_scope,
                    b.folder_id, b.title, b.url, b.description, b.tags, b.is_favorite,
                    b.created_by, b.updated_by, b.favicon_mime, b.favicon_hash, b.favicon_data,
                    b.favicon_updated_at, b.created_at, b.updated_at
             FROM rounddav_bookmarks b
             LEFT JOIN rounddav_bookmark_shares s ON s.bookmark_id = b.id
             WHERE {$whereSql}
             ORDER BY b.created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFolder(int $folderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM rounddav_bookmark_folders
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $folderId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getBookmark(int $bookmarkId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM rounddav_bookmarks
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $bookmarkId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertFolder(array $data): int
    {
        $sql = 'INSERT INTO rounddav_bookmark_folders
                    (owner_username, owner_domain, visibility, name, parent_id,
                     sort_order, created_by, updated_by, created_at, updated_at)
                VALUES
                    (:owner_username, :owner_domain, :visibility, :name, :parent_id,
                     :sort_order, :created_by, :updated_by, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':owner_username', $data['owner_username']);
        $stmt->bindValue(':owner_domain', $data['owner_domain']);
        $stmt->bindValue(':visibility', $data['visibility']);
        if ($data['parent_id'] === null) {
            $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':parent_id', $data['parent_id'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':sort_order', (int) $data['sort_order'], PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $data['created_by']);
        $stmt->bindValue(':updated_by', $data['updated_by']);
        $stmt->bindValue(':created_at', $data['created_at']);
        $stmt->bindValue(':updated_at', $data['updated_at']);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function updateFolder(int $id, array $data): void
    {
        $sql = 'UPDATE rounddav_bookmark_folders
                SET name = :name,
                    parent_id = :parent_id,
                    sort_order = :sort_order,
                    updated_by = :updated_by,
                    updated_at = :updated_at
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':name', $data['name']);
        if ($data['parent_id'] === null) {
            $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':parent_id', $data['parent_id'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':sort_order', (int) $data['sort_order'], PDO::PARAM_INT);
        $stmt->bindValue(':updated_by', $data['updated_by']);
        $stmt->bindValue(':updated_at', $data['updated_at']);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteFolder(int $id): void
    {
        // Unlink bookmarks first
        $stmt = $this->pdo->prepare(
            'UPDATE rounddav_bookmarks
             SET folder_id = NULL
             WHERE folder_id = :fid'
        );
        $stmt->execute([':fid' => $id]);

        $stmt = $this->pdo->prepare(
            'DELETE FROM rounddav_bookmark_folders WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function countChildFolders(int $folderId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM rounddav_bookmark_folders
             WHERE parent_id = :pid'
        );
        $stmt->execute([':pid' => $folderId]);
        return (int) $stmt->fetchColumn();
    }

    public function countBookmarksInFolder(int $folderId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM rounddav_bookmarks
             WHERE folder_id = :fid'
        );
        $stmt->execute([':fid' => $folderId]);
        return (int) $stmt->fetchColumn();
    }

    public function insertBookmark(array $data): int
    {
        $sql = 'INSERT INTO rounddav_bookmarks
                    (owner_username, owner_domain, visibility, share_scope, folder_id,
                     title, url, description, tags, is_favorite,
                     created_by, updated_by, favicon_mime, favicon_hash,
                     favicon_data, favicon_updated_at,
                     created_at, updated_at)
                VALUES
                    (:owner_username, :owner_domain, :visibility, :share_scope, :folder_id,
                     :title, :url, :description, :tags, :is_favorite,
                     :created_by, :updated_by, :favicon_mime, :favicon_hash,
                     :favicon_data, :favicon_updated_at,
                     :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':owner_username', $data['owner_username']);
        $stmt->bindValue(':owner_domain', $data['owner_domain']);
        $stmt->bindValue(':visibility', $data['visibility']);
        $stmt->bindValue(':share_scope', $data['share_scope']);
        if ($data['folder_id'] === null) {
            $stmt->bindValue(':folder_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':folder_id', $data['folder_id'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':url', $data['url']);
        if ($data['description'] === null) {
            $stmt->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':description', $data['description']);
        }
        if ($data['tags'] === null) {
            $stmt->bindValue(':tags', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':tags', $data['tags']);
        }
        $stmt->bindValue(':is_favorite', $data['is_favorite'], PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $data['created_by']);
        $stmt->bindValue(':updated_by', $data['updated_by']);
        $stmt->bindValue(':favicon_mime', $data['favicon_mime']);
        $stmt->bindValue(':favicon_hash', $data['favicon_hash']);
        if ($data['favicon_data'] === null) {
            $stmt->bindValue(':favicon_data', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':favicon_data', $data['favicon_data'], PDO::PARAM_LOB);
        }
        $stmt->bindValue(':favicon_updated_at', $data['favicon_updated_at']);
        $stmt->bindValue(':created_at', $data['created_at']);
        $stmt->bindValue(':updated_at', $data['updated_at']);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function updateBookmark(int $id, array $data): void
    {
        $sql = 'UPDATE rounddav_bookmarks
                SET title        = :title,
                    url          = :url,
                    description  = :description,
                    tags         = :tags,
                    folder_id    = :folder_id,
                    is_favorite  = :is_favorite,
                    share_scope  = :share_scope,
                    updated_by   = :updated_by,
                    updated_at   = :updated_at,
                    favicon_mime = :favicon_mime,
                    favicon_hash = :favicon_hash,
                    favicon_data = :favicon_data,
                    favicon_updated_at = :favicon_updated_at
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':url', $data['url']);
        if ($data['description'] === null) {
            $stmt->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':description', $data['description']);
        }
        if ($data['tags'] === null) {
            $stmt->bindValue(':tags', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':tags', $data['tags']);
        }
        if ($data['folder_id'] === null) {
            $stmt->bindValue(':folder_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':folder_id', $data['folder_id'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':is_favorite', $data['is_favorite'], PDO::PARAM_INT);
        $stmt->bindValue(':share_scope', $data['share_scope']);
        $stmt->bindValue(':updated_by', $data['updated_by']);
        $stmt->bindValue(':updated_at', $data['updated_at']);
        $stmt->bindValue(':favicon_mime', $data['favicon_mime']);
        $stmt->bindValue(':favicon_hash', $data['favicon_hash']);
        if ($data['favicon_data'] === null) {
            $stmt->bindValue(':favicon_data', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':favicon_data', $data['favicon_data'], PDO::PARAM_LOB);
        }
        $stmt->bindValue(':favicon_updated_at', $data['favicon_updated_at']);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
    }

    public function deleteBookmark(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rounddav_bookmarks WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function replaceBookmarkShares(int $bookmarkId, array $shares): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rounddav_bookmark_shares WHERE bookmark_id = :id');
        $stmt->execute([':id' => $bookmarkId]);

        if (!$shares) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO rounddav_bookmark_shares (bookmark_id, share_type, share_target, created_by, created_at)
             VALUES (:bookmark_id, :share_type, :share_target, :created_by, :created_at)'
        );

        foreach ($shares as $share) {
            $insert->execute([
                ':bookmark_id' => $bookmarkId,
                ':share_type'  => $share['share_type'],
                ':share_target'=> $share['share_target'],
                ':created_by'  => $share['created_by'],
                ':created_at'  => $share['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function getBookmarkShares(array $bookmarkIds): array
    {
        $bookmarkIds = array_values(array_filter(array_map('intval', $bookmarkIds)));
        if (!$bookmarkIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bookmarkIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT bookmark_id, share_type, share_target
             FROM rounddav_bookmark_shares
             WHERE bookmark_id IN ({$placeholders})
             ORDER BY share_type, share_target"
        );
        $stmt->execute($bookmarkIds);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int) $row['bookmark_id'];
            if (!isset($result[$bid])) {
                $result[$bid] = [];
            }
            $result[$bid][] = [
                'type'   => $row['share_type'],
                'target' => $row['share_target'],
            ];
        }
        return $result;
    }

    public function insertEvent(array $data): void
    {
        $sql = 'INSERT INTO rounddav_bookmark_events
                (bookmark_id, folder_id, owner_username, owner_domain, visibility, share_scope,
                 actor, action, details, created_at)
                VALUES
                (:bookmark_id, :folder_id, :owner_username, :owner_domain, :visibility, :share_scope,
                 :actor, :action, :details, :created_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':bookmark_id'    => $data['bookmark_id'] ?? null,
            ':folder_id'      => $data['folder_id'] ?? null,
            ':owner_username' => $data['owner_username'] ?? null,
            ':owner_domain'   => $data['owner_domain'],
            ':visibility'     => $data['visibility'],
            ':share_scope'    => $data['share_scope'] ?? 'private',
            ':actor'          => $data['actor'],
            ':action'         => $data['action'],
            ':details'        => isset($data['details']) ? json_encode($data['details']) : null,
            ':created_at'     => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function listEventsForUser(string $username, string $domain, int $limit = 40): array
    {
        $limit = max(5, min(200, $limit));

        $sql = "
            SELECT DISTINCT e.id, e.bookmark_id, e.folder_id, e.visibility, e.share_scope,
                            e.actor, e.action, e.details, e.created_at,
                            e.owner_username, e.owner_domain,
                            b.id AS active_bookmark_id,
                            b.title, b.url
            FROM rounddav_bookmark_events e
            LEFT JOIN rounddav_bookmarks b ON b.id = e.bookmark_id
            LEFT JOIN rounddav_bookmark_shares s ON s.bookmark_id = b.id
            WHERE (
                (b.id IS NOT NULL AND (
                    (b.visibility = 'private' AND b.owner_username = :username)
                    OR
                    (b.visibility = 'shared' AND (
                        (b.share_scope = 'domain' AND b.owner_domain = :domain)
                        OR
                        (b.share_scope = 'custom' AND (
                            (s.share_type = 'user' AND s.share_target = :username)
                            OR (s.share_type = 'domain' AND s.share_target = :domain)
                        ))
                    ))
                ))
                OR
                (b.id IS NULL AND (
                    (e.visibility = 'private' AND e.owner_username = :username)
                    OR
                    (e.visibility = 'shared' AND (
                        (e.share_scope = 'domain' AND e.owner_domain = :domain)
                        OR
                        (e.share_scope = 'custom')
                    ))
                ))
            )
            ORDER BY e.created_at DESC
            LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':username' => $username,
            ':domain'   => $domain,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['details'] = $row['details'] ? json_decode($row['details'], true) : [];
        }

        return $rows;
    }

    public function countPrivateBookmarks(string $username): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM rounddav_bookmarks
             WHERE owner_username = :u AND visibility = 'private'"
        );
        $stmt->execute([':u' => $username]);
        return (int) $stmt->fetchColumn();
    }

    public function countSharedBookmarks(string $domain): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM rounddav_bookmarks
             WHERE owner_domain = :d AND visibility = 'shared'"
        );
        $stmt->execute([':d' => $domain]);
        return (int) $stmt->fetchColumn();
    }

    public function bookmarkStats(): array
    {
        $sql = 'SELECT owner_domain,
                       SUM(CASE WHEN visibility = "private" THEN 1 ELSE 0 END) AS private_count,
                       SUM(CASE WHEN visibility = "shared" THEN 1 ELSE 0 END)  AS shared_count,
                       COUNT(*) AS total
                FROM rounddav_bookmarks
                GROUP BY owner_domain
                ORDER BY owner_domain';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function applyBookmarkFilters(array &$where, array &$params, array $filters, string $alias = 'rounddav_bookmarks'): void
    {
        if (!$filters) {
            return;
        }

        if (!empty($filters['favorite_only'])) {
            $where[] = "{$alias}.is_favorite = 1";
        }

        if (array_key_exists('folder_id', $filters) && $filters['folder_id'] !== '' && $filters['folder_id'] !== null) {
            $where[] = "{$alias}.folder_id = :filter_folder";
            $params[':filter_folder'] = (int) $filters['folder_id'];
        }

        if (!empty($filters['search'])) {
            $param = ':filter_search';
            $params[$param] = '%' . $filters['search'] . '%';
            $where[] = "({$alias}.title LIKE {$param} OR {$alias}.url LIKE {$param} OR {$alias}.description LIKE {$param} OR {$alias}.tags LIKE {$param})";
        }

        if (!empty($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : explode(',', (string) $filters['tags']);
            $idx = 0;
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $param = ':filter_tag_' . $idx++;
                $params[$param] = '%' . $tag . '%';
                $where[] = "{$alias}.tags LIKE {$param}";
            }
        }
    }
}
