<?php

namespace RoundDAV\Dav;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use RoundDAV\Security\RateLimitGuard;

/**
 * Request rate-limiting plugin for DAV methods.
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
        $server->on('beforeMethod:*', [$this, 'beforeMethod'], 5);
    }

    public function beforeMethod(RequestInterface $request, ResponseInterface $response)
    {
        $result = RateLimitGuard::checkAndConsume($this->config, 'dav');

        foreach (RateLimitGuard::headerMap($result) as $name => $value) {
            $response->setHeader($name, $value);
        }

        if (!empty($result['allowed'])) {
            return;
        }

        $response->setStatus(429);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->setBody("Too Many Requests\n");

        return false;
    }
}
