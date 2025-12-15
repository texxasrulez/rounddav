<?php
// src/Provision/UserProvisioner.php

namespace RoundDAV\Provision;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Handles creation and update of users, principals, and default collections.
 *
 * Assumes SabreDAV 4.x-style tables:
 *  - principals(uri, displayname, email, ...)
 *  - calendars(id, synctoken, components)
 *  - calendarinstances(calendarid, principaluri, displayname, uri, ...)
 *  - addressbooks(principaluri, displayname, uri, ...)
 *  - rounddav_users(username, password_hash, principal_uri, active, ...)
 */
class UserProvisioner
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo    = $pdo;
        $this->config = $config;
    }

    /**
     * Provision or update a user in RoundDAV.
     *
     * - Creates/updates principals row
     * - Creates/updates rounddav_users record
     * - Ensures a default addressbook and calendar for the principal
     *
     * @param string $username Roundcube username (usually email)
     * @param string $password Plain-text password (will be hashed for DAV)
     * @param array  $options  ['displayname' => string, 'email' => string|null]
     */
    public function provisionUser(string $username, string $password, array $options = []): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('Username cannot be empty.');
        }

        $displayName = $options['displayname'] ?? $username;
        $email       = $options['email'] ?? (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null);

        $provisionCfg  = $this->config['provision'] ?? [];
        $principalRoot = $provisionCfg['principal_prefix'] ?? 'principals';

        // e.g. "principals/gene@genesworld.net"
        $principalUri = rtrim($principalRoot, '/') . '/' . $username;

        $this->pdo->beginTransaction();

        try {
            $principalId = $this->ensurePrincipal($principalUri, $displayName, $email);
            $this->ensureRounddavUser($username, $password, $principalUri);
            $this->ensureDefaultAddressBook($principalUri);
            $this->ensureDefaultCalendar($principalUri);

            $extraCalendars    = isset($options['extra_calendars']) && is_array($options['extra_calendars'])
                ? $options['extra_calendars']
                : [];
            $extraAddressbooks = isset($options['extra_addressbooks']) && is_array($options['extra_addressbooks'])
                ? $options['extra_addressbooks']
                : [];

            foreach ($extraCalendars as $cal) {
                if (is_array($cal)) {
                    $this->ensureExtraCalendar($principalUri, $cal);
                }
            }

            foreach ($extraAddressbooks as $ab) {
                if (is_array($ab)) {
                    $this->ensureExtraAddressBook($principalUri, $ab);
                }
            }


            $this->pdo->commit();

            return [
                'status'        => 'ok',
                'message'       => 'Provisioning OK for ' . $username,
                'principal_uri' => $principalUri,
                'principal_id'  => $principalId,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException(
                'Provisioning failed for ' . $username . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Ensure a principal row exists and is up to date.
     *
     * @return int principal id
     */
    private function ensurePrincipal(string $principalUri, string $displayName, ?string $email): int
    {
        // Standard SabreDAV "principals" table layout is assumed
        $select = 'SELECT id FROM principals WHERE uri = :uri';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([':uri' => $principalUri]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            // Keep it simple: update displayname/email to follow Roundcube
            $update = 'UPDATE principals
                       SET displayname = :dn, email = :email
                       WHERE id = :id';
            $stmt   = $this->pdo->prepare($update);
            $stmt->execute([
                ':dn'    => $displayName,
                ':email' => $email,
                ':id'    => (int) $id,
            ]);

            return (int) $id;
        }

        // Insert new principal
        $insert = 'INSERT INTO principals (uri, displayname, email)
                   VALUES (:uri, :dn, :email)';
        $stmt   = $this->pdo->prepare($insert);
        $stmt->execute([
            ':uri'   => $principalUri,
            ':dn'    => $displayName,
            ':email' => $email,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ensure there is a row in rounddav_users for this username.
     */
    private function ensureRounddavUser(string $username, string $password, string $principalUri): void
    {
        // rounddav_users table is defined in config/rounddav.mysql.sql
        $select = 'SELECT id FROM rounddav_users WHERE username = :u';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([':u' => $username]);
        $id = $stmt->fetchColumn();

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($id !== false) {
            $update = 'UPDATE rounddav_users
                       SET password_hash = :hash,
                           principal_uri = :puri,
                           active        = 1
                       WHERE id = :id';
            $stmt   = $this->pdo->prepare($update);
            $stmt->execute([
                ':hash' => $hash,
                ':puri' => $principalUri,
                ':id'   => (int) $id,
            ]);
            return;
        }

        $insert = 'INSERT INTO rounddav_users (username, password_hash, principal_uri, active)
                   VALUES (:u, :hash, :puri, 1)';
        $stmt   = $this->pdo->prepare($insert);
        $stmt->execute([
            ':u'    => $username,
            ':hash' => $hash,
            ':puri' => $principalUri,
        ]);
    }

    /**
     * Ensure the principal has a default calendar (Sabre 4.x layout).
     *
     * Uses:
     *  - calendars(id, synctoken, components)
     *  - calendarinstances(calendarid, principaluri, displayname, uri, ...)
     */
    private function ensureDefaultCalendar(string $principalUri): void
    {
        // 1) Check if a "default" calendarinstance already exists
        $select = 'SELECT id
                   FROM calendarinstances
                   WHERE principaluri = :puri
                     AND uri = :uri';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([
            ':puri' => $principalUri,
            ':uri'  => 'default',
        ]);

        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return;
        }

        // 2) Create a base calendars record
        $insertCal = 'INSERT INTO calendars (synctoken, components)
                      VALUES (:synctoken, :components)';
        $stmt      = $this->pdo->prepare($insertCal);
        $stmt->execute([
            ':synctoken'  => 1,
            ':components' => 'VEVENT,VTODO',
        ]);

        $calendarId = (int) $this->pdo->lastInsertId();

        // 3) Link it to this principal via calendarinstances
        $insertInst = 'INSERT INTO calendarinstances (
                           calendarid,
                           principaluri,
                           access,
                           displayname,
                           uri,
                           description,
                           calendarorder,
                           calendarcolor,
                           timezone,
                           transparent,
                           share_href,
                           share_displayname,
                           share_invitestatus
                       ) VALUES (
                           :calendarid,
                           :puri,
                           :access,
                           :displayname,
                           :uri,
                           :description,
                           :calendarorder,
                           :calendarcolor,
                           :timezone,
                           :transparent,
                           :share_href,
                           :share_displayname,
                           :share_invitestatus
                       )';

        $stmt = $this->pdo->prepare($insertInst);

        try {
            $stmt->execute([
                ':calendarid'         => $calendarId,
                ':puri'               => $principalUri,
                ':access'             => 1,          // owner
                ':displayname'        => 'Calendar',
                ':uri'                => 'default',
                ':description'        => null,
                ':calendarorder'      => 0,
                ':calendarcolor'      => null,
                ':timezone'           => null,
                ':transparent'        => 0,
                ':share_href'         => null,
                ':share_displayname'  => null,
                ':share_invitestatus' => 2,          // accepted
            ]);
        } catch (PDOException $e) {
            // If someone else created the same instance in the meantime,
            // ignore the duplicate and move on.
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    }

    /**
     * Ensure the principal has a default addressbook.
     */
    
    /**
     * Create an additional addressbook for this principal if it doesn't exist.
     *
     * @param string $principalUri
     * @param array  $def ['uri' => string, 'displayname' => string|null, 'shared' => bool|null]
     */
    private function ensureExtraAddressBook(string $principalUri, array $def): void
    {
        $uri = isset($def['uri']) ? trim((string) $def['uri']) : '';
        if ($uri === '' || $uri === 'default') {
            return;
        }

        $displayName = isset($def['displayname']) && $def['displayname'] !== ''
            ? (string) $def['displayname']
            : $uri;

        $select = 'SELECT id FROM addressbooks
                   WHERE principaluri = :puri
                     AND uri = :uri';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([
            ':puri' => $principalUri,
            ':uri'  => $uri,
        ]);

        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return;
        }

        $insert = 'INSERT INTO addressbooks (principaluri, displayname, uri)
                   VALUES (:puri, :displayname, :uri)';
        $stmt   = $this->pdo->prepare($insert);

        try {
            $stmt->execute([
                ':puri'        => $principalUri,
                ':displayname' => $displayName,
                ':uri'         => $uri,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    }

    /**
     * Create an additional calendar for this principal if it doesn't exist.
     *
     * @param string $principalUri
     * @param array  $def ['uri' => string, 'displayname' => string|null, 'mode' => 'events'|'tasks'|'both', 'shared' => bool|null]
     */
    private function ensureExtraCalendar(string $principalUri, array $def): void
    {
        $uri = isset($def['uri']) ? trim((string) $def['uri']) : '';
        if ($uri === '' || $uri === 'default') {
            return;
        }

        $displayName = isset($def['displayname']) && $def['displayname'] !== ''
            ? (string) $def['displayname']
            : $uri;

        // Map "mode" to SabreDAV components
        $mode = isset($def['mode']) ? strtolower((string) $def['mode']) : 'both';
        switch ($mode) {
            case 'events':
                $components = 'VEVENT';
                break;
            case 'tasks':
                $components = 'VTODO';
                break;
            default:
                $components = 'VEVENT,VTODO';
                break;
        }

        // 1) Check if this calendar instance already exists
        $select = 'SELECT id
                   FROM calendarinstances
                   WHERE principaluri = :puri
                     AND uri = :uri';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([
            ':puri' => $principalUri,
            ':uri'  => $uri,
        ]);

        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return;
        }

        // 2) Create a base calendars record
        $insertCal = 'INSERT INTO calendars (synctoken, components)
                      VALUES (:synctoken, :components)';
        $stmt      = $this->pdo->prepare($insertCal);
        $stmt->execute([
            ':synctoken'  => 1,
            ':components' => $components,
        ]);

        $calendarId = (int) $this->pdo->lastInsertId();

        // 3) Link it to this principal via calendarinstances
        $insertInst = 'INSERT INTO calendarinstances (
                           calendarid,
                           principaluri,
                           access,
                           displayname,
                           uri,
                           description,
                           calendarorder,
                           calendarcolor,
                           timezone,
                           transparent,
                           share_href,
                           share_displayname,
                           share_invitestatus
                       ) VALUES (
                           :calendarid,
                           :puri,
                           :access,
                           :displayname,
                           :uri,
                           :description,
                           :calendarorder,
                           :calendarcolor,
                           :timezone,
                           :transparent,
                           :share_href,
                           :share_displayname,
                           :share_invitestatus
                       )';

        $stmt = $this->pdo->prepare($insertInst);

        try {
            $stmt->execute([
                ':calendarid'         => $calendarId,
                ':puri'               => $principalUri,
                ':access'             => 1,
                ':displayname'        => $displayName,
                ':uri'                => $uri,
                ':description'        => null,
                ':calendarorder'      => 0,
                ':calendarcolor'      => null,
                ':timezone'           => null,
                ':transparent'        => 0,
                ':share_href'         => null,
                ':share_displayname'  => null,
                ':share_invitestatus' => 2,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    }

private function ensureDefaultAddressBook(string $principalUri): void
    {
        // SabreDAV "addressbooks" table
        $select = 'SELECT id FROM addressbooks
                   WHERE principaluri = :puri
                     AND uri = :uri';
        $stmt   = $this->pdo->prepare($select);
        $stmt->execute([
            ':puri' => $principalUri,
            ':uri'  => 'default',
        ]);

        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return;
        }

        $insert = 'INSERT INTO addressbooks (principaluri, displayname, uri)
                   VALUES (:puri, :displayname, :uri)';
        $stmt   = $this->pdo->prepare($insert);

        try {
            $stmt->execute([
                ':puri'        => $principalUri,
                ':displayname' => 'Contacts',
                ':uri'         => 'default',
            ]);
        } catch (PDOException $e) {
            // Same idea as calendarinstances: ignore duplicates if they happen.
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    }
}
