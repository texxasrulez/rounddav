<?php
declare(strict_types=1);

namespace RoundDAV\Security;

final class RateLimitGuard
{
    /**
     * Check and consume one request token for the caller IP and scope.
     *
     * Returns:
     * - allowed (bool)
     * - limit (int)
     * - remaining (int)
     * - reset_at (int unix timestamp)
     * - retry_after (int|null seconds)
     * - ip (string)
     */
    public static function checkAndConsume(array $config, string $scope = 'global'): array
    {
        $now = time();
        $ip = self::clientIp($config);
        $policy = self::effectivePolicy($config, $scope);
        $enabled = !empty($policy['enabled']);
        $limit = max(1, (int) ($policy['max_requests'] ?? 100));
        $window = max(1, (int) ($policy['window_sec'] ?? 300));
        $blockSec = max(1, (int) ($policy['block_minutes'] ?? 30)) * 60;

        if (self::isAllowlisted($ip, (array) ($policy['ip_allowlist'] ?? []))) {
            return [
                'allowed'     => true,
                'limit'       => $limit,
                'remaining'   => $limit,
                'reset_at'    => $now + $window,
                'retry_after' => null,
                'ip'          => $ip,
                'scope'       => $scope,
                'allowlisted' => true,
            ];
        }

        if (!$enabled) {
            return [
                'allowed'     => true,
                'limit'       => $limit,
                'remaining'   => $limit,
                'reset_at'    => $now + $window,
                'retry_after' => null,
                'ip'          => $ip,
                'scope'       => $scope,
            ];
        }

        $store = self::storePath($scope, $ip);
        $dir = dirname($store);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $fp = @fopen($store, 'c+');
        if (!$fp) {
            // Fail-open to avoid a storage outage becoming a service outage.
            return [
                'allowed'     => true,
                'limit'       => $limit,
                'remaining'   => max(0, $limit - 1),
                'reset_at'    => $now + $window,
                'retry_after' => null,
                'ip'          => $ip,
                'scope'       => $scope,
            ];
        }

        $allowed = true;
        $retryAfter = null;
        $remaining = $limit;
        $resetAt = $now + $window;

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('lock failed');
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $state = json_decode((string) $raw, true);
            if (!is_array($state)) {
                $state = [];
            }

            $windowStart = isset($state['window_start']) ? (int) $state['window_start'] : $now;
            $count = isset($state['count']) ? (int) $state['count'] : 0;
            $blockedUntil = isset($state['blocked_until']) ? (int) $state['blocked_until'] : 0;

            if ($blockedUntil > $now) {
                $allowed = false;
                $retryAfter = $blockedUntil - $now;
                $remaining = 0;
                $resetAt = $blockedUntil;
            } else {
                if (($now - $windowStart) >= $window) {
                    $windowStart = $now;
                    $count = 0;
                }

                $count++;
                if ($count > $limit) {
                    $allowed = false;
                    $blockedUntil = $now + $blockSec;
                    $retryAfter = $blockSec;
                    $remaining = 0;
                    $resetAt = $blockedUntil;
                } else {
                    $allowed = true;
                    $remaining = max(0, $limit - $count);
                    $resetAt = $windowStart + $window;
                }
            }

            $newState = [
                'scope'       => $scope,
                'ip'          => $ip,
                'window_start' => $windowStart,
                'count'        => $count,
                'blocked_until'=> $blockedUntil,
                'updated_at'   => $now,
            ];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($newState));
            fflush($fp);
            flock($fp, LOCK_UN);
        } catch (\Throwable $e) {
            @flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'allowed'     => true,
                'limit'       => $limit,
                'remaining'   => max(0, $limit - 1),
                'reset_at'    => $now + $window,
                'retry_after' => null,
                'ip'          => $ip,
                'scope'       => $scope,
            ];
        }

        fclose($fp);

        return [
            'allowed'     => $allowed,
            'limit'       => $limit,
            'remaining'   => $remaining,
            'reset_at'    => $resetAt,
            'retry_after' => $retryAfter,
            'ip'          => $ip,
            'scope'       => $scope,
        ];
    }

    public static function headerMap(array $result): array
    {
        $headers = [
            'X-RateLimit-Limit'     => (string) ($result['limit'] ?? 0),
            'X-RateLimit-Remaining' => (string) ($result['remaining'] ?? 0),
            'X-RateLimit-Reset'     => (string) ($result['reset_at'] ?? time()),
        ];

        if (!empty($result['retry_after'])) {
            $headers['Retry-After'] = (string) $result['retry_after'];
        }

        return $headers;
    }

    public static function clientIp(array $config): string
    {
        $trustProxy = !empty($config['security']['trust_proxy_headers']);

        if ($trustProxy) {
            $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($xff !== '') {
                $parts = explode(',', $xff);
                $candidate = trim((string) $parts[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return 'unknown';
    }

    private static function storePath(string $scope, string $ip): string
    {
        $base = dirname(__DIR__, 2);
        $safeScope = preg_replace('/[^a-z0-9_.-]+/i', '_', strtolower($scope));
        $hash = sha1($safeScope . '|' . $ip);
        return $base . '/var/rate_limit/' . $safeScope . '_' . $hash . '.json';
    }

    private static function effectivePolicy(array $config, string $scope): array
    {
        $rate = (array) ($config['security']['rate_limit'] ?? []);

        $policy = [
            'enabled'       => !empty($rate['enabled']),
            'max_requests'  => (int) ($rate['max_requests'] ?? 100),
            'window_sec'    => (int) ($rate['window_sec'] ?? 300),
            'block_minutes' => (int) ($rate['block_minutes'] ?? 30),
            'ip_allowlist'  => self::normalizeAllowlist($rate['ip_allowlist'] ?? []),
        ];

        $overrides = (array) ($rate['scope_overrides'] ?? []);
        $scopeKey = strtolower(trim($scope));
        if ($scopeKey !== '' && !empty($overrides[$scopeKey]) && is_array($overrides[$scopeKey])) {
            $ovr = $overrides[$scopeKey];
            if (array_key_exists('enabled', $ovr)) {
                $policy['enabled'] = !empty($ovr['enabled']);
            }
            if (array_key_exists('max_requests', $ovr)) {
                $policy['max_requests'] = (int) $ovr['max_requests'];
            }
            if (array_key_exists('window_sec', $ovr)) {
                $policy['window_sec'] = (int) $ovr['window_sec'];
            }
            if (array_key_exists('block_minutes', $ovr)) {
                $policy['block_minutes'] = (int) $ovr['block_minutes'];
            }
        }

        $policy['max_requests'] = max(1, (int) $policy['max_requests']);
        $policy['window_sec'] = max(1, (int) $policy['window_sec']);
        $policy['block_minutes'] = max(1, (int) $policy['block_minutes']);

        return $policy;
    }

    private static function normalizeAllowlist($input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\s,;]+/', $input);
        } elseif (!is_array($input)) {
            $input = [];
        }

        $list = [];
        foreach ($input as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            $list[$entry] = true;
        }

        return array_keys($list);
    }

    private static function isAllowlisted(string $ip, array $allowlist): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($allowlist as $rule) {
            $rule = strtolower(trim((string) $rule));
            if ($rule === '') {
                continue;
            }

            if ($rule === strtolower($ip)) {
                return true;
            }

            if (strpos($rule, '/') !== false && self::ipMatchesCidr($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($network === null || $prefix === null || !is_numeric($prefix)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        $prefixLen = (int) $prefix;
        if ($prefixLen < 0 || $prefixLen > $bits) {
            return false;
        }

        $bytes = intdiv($prefixLen, 8);
        $remBits = $prefixLen % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        if ($remBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
