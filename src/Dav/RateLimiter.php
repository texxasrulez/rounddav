<?php

namespace RoundDAV\Dav;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Minimal no-op RateLimiter stub.
 * We’ll implement real logic later.
 */
class RateLimiter extends ServerPlugin
{
    private \PDO $pdo;
    private array $config;

    public function __construct(\PDO $pdo, array $config)
    {
        $this->pdo    = $pdo;
        $this->config = $config;
    }

    public function initialize(Server $server): void
    {
        // No-op for now; later we’ll hook into beforeMethod, etc.
    }
}
