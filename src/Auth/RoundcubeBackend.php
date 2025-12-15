<?php
// src/Auth/RoundcubeBackend.php

namespace RoundDAV\Auth;

use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\DAV\Exception\NotAuthenticated;
use PDO;

class RoundcubeBackend extends AbstractBasic
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validates a username/password against the rounddav_users table.
     *
     * This is intentionally decoupled from Roundcube's own DB. The Roundcube
     * integration plugin will provision users into rounddav_users using the
     * provisioning API, keeping passwords in sync.
     */
    protected function validateUserPass($username, $password)
    {
        if ($username === '' || $password === '') {
            throw new NotAuthenticated('Empty username or password.');
        }

        $sql = 'SELECT password_hash, active FROM rounddav_users WHERE username = :username LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // User not provisioned yet
            throw new NotAuthenticated('User not provisioned in RoundDAV.');
        }

        if ((int)$row['active'] !== 1) {
            throw new NotAuthenticated('User is disabled in RoundDAV.');
        }

        $hash = $row['password_hash'] ?? '';

        if ($hash === '' || !password_verify($password, $hash)) {
            throw new NotAuthenticated('Invalid credentials.');
        }

        // At this point authentication is successful.
        return true;
    }
}
