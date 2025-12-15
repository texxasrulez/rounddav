-- SabreDAV core schema for MySQL (CalDAV + CardDAV)
-- Compatible with sabre/dav 4.x / Kolab usage.
-- Key rule: only calendarinstances has `displayname`, NOT calendars.

-- =========================================================
-- CalDAV (calendars)
-- =========================================================

CREATE TABLE IF NOT EXISTS `calendars` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `synctoken`  INT UNSIGNED NOT NULL DEFAULT 1,
  `components` VARBINARY(21) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendarinstances` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `calendarid`         INT UNSIGNED NOT NULL,
  `principaluri`       VARBINARY(100) DEFAULT NULL,
  `access`             TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=owner,2=read,3=readwrite',
  `displayname`        VARCHAR(100) DEFAULT NULL,
  `uri`                VARBINARY(200) DEFAULT NULL,
  `description`        TEXT DEFAULT NULL,
  `calendarorder`      INT UNSIGNED NOT NULL DEFAULT 0,
  `calendarcolor`      VARBINARY(10) DEFAULT NULL,
  `timezone`           TEXT DEFAULT NULL,
  `transparent`        TINYINT(1) NOT NULL DEFAULT 0,
  `share_href`         VARBINARY(100) DEFAULT NULL,
  `share_displayname`  VARCHAR(100) DEFAULT NULL,
  `share_invitestatus` TINYINT(1) NOT NULL DEFAULT 2 COMMENT '1=noresponse,2=accepted,3=declined,4=invalid',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cal_principal_uri` (`principaluri`, `uri`),
  UNIQUE KEY `uniq_cal_calendar_principal` (`calendarid`, `principaluri`),
  UNIQUE KEY `uniq_cal_calendar_share_href` (`calendarid`, `share_href`),
  KEY `idx_calinst_calendarid` (`calendarid`),

  CONSTRAINT `fk_calinst_calendar`
    FOREIGN KEY (`calendarid`) REFERENCES `calendars` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendarobjects` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `calendardata`   MEDIUMBLOB,
  `uri`            VARBINARY(200),
  `calendarid`     INT UNSIGNED NOT NULL,
  `lastmodified`   INT UNSIGNED DEFAULT NULL,
  `etag`           VARBINARY(32) DEFAULT NULL,
  `size`           INT UNSIGNED NOT NULL,
  `componenttype`  VARBINARY(8) DEFAULT NULL,
  `firstoccurence` INT UNSIGNED DEFAULT NULL,
  `lastoccurence`  INT UNSIGNED DEFAULT NULL,
  `uid`            VARBINARY(200) DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_calobj_calendar_uri` (`calendarid`,`uri`),
  KEY `calendarid_time` (`calendarid`,`firstoccurence`),

  CONSTRAINT `fk_calobj_calendar`
    FOREIGN KEY (`calendarid`) REFERENCES `calendars` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendarchanges` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uri`        VARBINARY(200) NOT NULL,
  `synctoken`  INT UNSIGNED NOT NULL,
  `calendarid` INT UNSIGNED NOT NULL,
  `operation`  TINYINT(1) NOT NULL,

  PRIMARY KEY (`id`),
  KEY `calendarid_synctoken` (`calendarid`,`synctoken`),

  CONSTRAINT `fk_calchg_calendar`
    FOREIGN KEY (`calendarid`) REFERENCES `calendars` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendarsubscriptions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uri`              VARBINARY(200) NOT NULL,
  `principaluri`     VARBINARY(255) NOT NULL,
  `source`           TEXT,
  `displayname`      VARCHAR(100),
  `refreshrate`      VARCHAR(10),
  `calendarorder`    INT UNSIGNED NOT NULL DEFAULT 0,
  `calendarcolor`    VARBINARY(10),
  `striptodos`       TINYINT(1) DEFAULT NULL,
  `stripalarms`      TINYINT(1) DEFAULT NULL,
  `stripattachments` TINYINT(1) DEFAULT NULL,
  `lastmodified`     INT UNSIGNED DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_calsub_principal_uri` (`principaluri`, `uri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schedulingobjects` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `principaluri` VARBINARY(255),
  `calendardata` MEDIUMBLOB,
  `uri`          VARBINARY(200),
  `lastmodified` INT UNSIGNED,
  `etag`         VARBINARY(32),
  `size`         INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_sched_principal` (`principaluri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CardDAV (addressbooks)
-- =========================================================

CREATE TABLE IF NOT EXISTS `addressbooks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `principaluri` VARBINARY(255),
  `displayname`  VARCHAR(255),
  `uri`          VARBINARY(200),
  `description`  TEXT,
  `synctoken`    INT UNSIGNED NOT NULL DEFAULT 1,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_abook_principal_uri` (`principaluri`(100),`uri`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cards` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `addressbookid` INT UNSIGNED NOT NULL,
  `carddata`      MEDIUMBLOB,
  `uri`           VARBINARY(200),
  `lastmodified`  INT UNSIGNED,
  `etag`          VARBINARY(32),
  `size`          INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_card_abook` (`addressbookid`),

  CONSTRAINT `fk_card_abook`
    FOREIGN KEY (`addressbookid`) REFERENCES `addressbooks` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `addressbookchanges` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uri`           VARBINARY(200) NOT NULL,
  `synctoken`     INT UNSIGNED NOT NULL,
  `addressbookid` INT UNSIGNED NOT NULL,
  `operation`     TINYINT(1) NOT NULL,

  PRIMARY KEY (`id`),
  KEY `addressbookid_synctoken` (`addressbookid`,`synctoken`),

  CONSTRAINT `fk_abchg_abook`
    FOREIGN KEY (`addressbookid`) REFERENCES `addressbooks` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Locks
-- =========================================================

CREATE TABLE IF NOT EXISTS `locks` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner`   VARCHAR(100),
  `timeout` INT UNSIGNED,
  `created` INT,
  `token`   VARBINARY(100),
  `scope`   TINYINT,
  `depth`   TINYINT,
  `uri`     VARBINARY(1000),

  PRIMARY KEY (`id`),
  KEY `idx_lock_token` (`token`),
  KEY `idx_lock_uri` (`uri`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Principals / ACL
-- =========================================================

CREATE TABLE IF NOT EXISTS `principals` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uri`         VARBINARY(200) NOT NULL,
  `email`       VARBINARY(80),
  `displayname` VARCHAR(80),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_principal_uri` (`uri`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groupmembers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `principal_id` INT UNSIGNED NOT NULL,
  `member_id`    INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_group_member` (`principal_id`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Property storage
-- =========================================================

CREATE TABLE IF NOT EXISTS `propertystorage` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path`      VARBINARY(1024) NOT NULL,
  `name`      VARBINARY(100) NOT NULL,
  `valuetype` INT UNSIGNED,
  `value`     MEDIUMBLOB,

  PRIMARY KEY (`id`),
  UNIQUE KEY `path_property` (`path`(600),`name`(100)),
  KEY `idx_prop_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Auth users (SabreDAV PDO auth backend)
-- =========================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(190) NOT NULL,
  `digesta1` VARCHAR(255) NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
