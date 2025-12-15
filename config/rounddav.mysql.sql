-- rounddav.mysql.sql
-- RoundDAV companion tables.
-- Assumes sabredav.mysql.sql has already been applied.

CREATE TABLE IF NOT EXISTS rounddav_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(190) NOT NULL,   -- usually the Roundcube login (email)
  password_hash VARCHAR(255) NOT NULL,   -- hashed DAV password
  principal_uri VARCHAR(255) NOT NULL,   -- e.g. 'principals/gene@genesworld.net'
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_rounddav_username (username),
  UNIQUE KEY uniq_rounddav_principal (principal_uri)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_global_collections (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('addressbook','calendar') NOT NULL,
  uri         VARCHAR(255) NOT NULL,      -- server-relative DAV collection path
  displayname VARCHAR(255) DEFAULT NULL,
  description TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_rounddav_collection_uri (uri)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_global_permissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id INT UNSIGNED NOT NULL,
  principal_uri VARCHAR(255) NOT NULL,
  read_only     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_rounddav_perm_collection (collection_id),
  KEY idx_rounddav_perm_principal (principal_uri),
  CONSTRAINT fk_rounddav_perm_collection
    FOREIGN KEY (collection_id)
      REFERENCES rounddav_global_collections(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_rate_limits (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address    VARBINARY(16) NOT NULL,   -- IPv4 / IPv6
  username      VARCHAR(190) NULL,
  window_start  DATETIME NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rounddav_rl_ip (ip_address),
  KEY idx_rounddav_rl_user (username),
  KEY idx_rounddav_rl_block (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_bookmark_domains (
  domain          VARCHAR(190) NOT NULL,
  shared_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  shared_label    VARCHAR(190) DEFAULT NULL,
  max_private     INT UNSIGNED DEFAULT NULL,
  max_shared      INT UNSIGNED DEFAULT NULL,
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_bookmark_folders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_username VARCHAR(190) DEFAULT NULL,
  owner_domain   VARCHAR(190) NOT NULL,
  visibility     ENUM('private','shared') NOT NULL DEFAULT 'private',
  name           VARCHAR(255) NOT NULL,
  parent_id      INT UNSIGNED DEFAULT NULL,
  sort_order     INT DEFAULT 0,
  created_by     VARCHAR(190) NOT NULL,
  updated_by     VARCHAR(190) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rdbk_folders_owner (owner_username),
  KEY idx_rdbk_folders_domain (owner_domain),
  KEY idx_rdbk_folders_parent (parent_id),
  CONSTRAINT fk_rdbk_folder_parent
    FOREIGN KEY (parent_id)
      REFERENCES rounddav_bookmark_folders(id)
      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_bookmarks (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_username   VARCHAR(190) DEFAULT NULL,
  owner_domain     VARCHAR(190) NOT NULL,
  visibility       ENUM('private','shared') NOT NULL DEFAULT 'private',
  share_scope      ENUM('domain','custom') NOT NULL DEFAULT 'domain',
  folder_id        INT UNSIGNED DEFAULT NULL,
  title            VARCHAR(255) NOT NULL,
  url              TEXT NOT NULL,
  description      TEXT NULL,
  tags             TEXT NULL,
  is_favorite      TINYINT(1) NOT NULL DEFAULT 0,
  created_by       VARCHAR(190) NOT NULL,
  updated_by       VARCHAR(190) DEFAULT NULL,
  favicon_mime     VARCHAR(64) DEFAULT NULL,
  favicon_hash     CHAR(40) DEFAULT NULL,
  favicon_data     MEDIUMBLOB NULL,
  favicon_updated_at DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rdbk_owner (owner_username),
  KEY idx_rdbk_domain (owner_domain),
  KEY idx_rdbk_folder (folder_id),
  KEY idx_rdbk_visibility (visibility),
  CONSTRAINT fk_rdbk_folder
    FOREIGN KEY (folder_id)
      REFERENCES rounddav_bookmark_folders(id)
      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_bookmark_shares (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bookmark_id   INT UNSIGNED NOT NULL,
  share_type    ENUM('user','domain') NOT NULL,
  share_target  VARCHAR(190) NOT NULL,
  created_by    VARCHAR(190) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rdbk_share_target (share_type, share_target),
  KEY idx_rdbk_share_bookmark (bookmark_id),
  CONSTRAINT fk_rdbk_share_bookmark
    FOREIGN KEY (bookmark_id)
      REFERENCES rounddav_bookmarks(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rounddav_bookmark_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bookmark_id    INT UNSIGNED DEFAULT NULL,
  folder_id      INT UNSIGNED DEFAULT NULL,
  owner_username VARCHAR(190) DEFAULT NULL,
  owner_domain   VARCHAR(190) NOT NULL,
  visibility     ENUM('private','shared') NOT NULL,
  share_scope    ENUM('domain','custom','private') NOT NULL,
  actor          VARCHAR(190) NOT NULL,
  action         VARCHAR(32) NOT NULL,
  details        TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rdbk_evt_bookmark (bookmark_id),
  KEY idx_rdbk_evt_owner (owner_username),
  KEY idx_rdbk_evt_domain (owner_domain),
  CONSTRAINT fk_rdbk_evt_bookmark
    FOREIGN KEY (bookmark_id)
      REFERENCES rounddav_bookmarks(id)
      ON DELETE SET NULL,
  CONSTRAINT fk_rdbk_evt_folder
    FOREIGN KEY (folder_id)
      REFERENCES rounddav_bookmark_folders(id)
      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
