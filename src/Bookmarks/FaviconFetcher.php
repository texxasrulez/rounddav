<?php
// src/Bookmarks/FaviconFetcher.php

namespace RoundDAV\Bookmarks;

/**
 * Minimal favicon downloader with tight limits (size + timeout).
 *
 * We only try the classic /favicon.ico location to avoid parsing HTML
 * or loading arbitrary resources.
 */
class FaviconFetcher
{
    private int $timeout;
    private int $maxBytes;

    public function __construct(int $timeout = 4, int $maxBytes = 24576)
    {
        $this->timeout  = max(1, $timeout);
        $this->maxBytes = max(1024, $maxBytes);
    }

    /**
     * Attempt to fetch a favicon for the provided URL.
     *
     * @return array|null ['mime' => string, 'data' => string(binary), 'hash' => string]
     */
    public function fetch(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $faviconUrl = $this->buildFaviconUrl($url);
        if ($faviconUrl === null) {
            return null;
        }

        $buffer = '';
        $ch     = curl_init($faviconUrl);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RoundDAV-FaviconFetcher');
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer) {
            $chunkLen = strlen($data);
            if ($chunkLen === 0) {
                return 0;
            }
            $remaining = $this->maxBytes - strlen($buffer);
            if ($remaining <= 0) {
                return 0;
            }
            if ($chunkLen > $remaining) {
                $buffer .= substr($data, 0, $remaining);
                return 0;
            }
            $buffer .= $data;
            return $chunkLen;
        });

        $success = curl_exec($ch);
        $info    = curl_getinfo($ch);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($success === false || ($info['http_code'] ?? 0) >= 400) {
            return null;
        }

        if ($buffer === '') {
            return null;
        }

        if (strlen($buffer) > $this->maxBytes) {
            $buffer = substr($buffer, 0, $this->maxBytes);
        }

        $mime = $info['content_type'] ?? null;
        if (!is_string($mime) || $mime === '') {
            $mime = $this->guessMime($buffer) ?? 'image/x-icon';
        }

        return [
            'mime' => $mime,
            'data' => $buffer,
            'hash' => sha1($buffer),
        ];
    }

    private function buildFaviconUrl(string $url): ?string
    {
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host   = strtolower($parts['host']);
        $portNum = isset($parts['port']) ? (int) $parts['port'] : null;
        $port   = $portNum ? ':' . $portNum : '';

        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        if ($portNum !== null && !in_array($portNum, [80, 443], true)) {
            return null;
        }

        if ($this->isBlockedHost($host)) {
            return null;
        }

        if (!$this->hostResolvesToPublicIp($host)) {
            return null;
        }

        return sprintf('%s://%s%s/favicon.ico', $scheme, $host, $port);
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !$this->isPublicIp($host);
        }

        return false;
    }

    private function hostResolvesToPublicIp(string $host): bool
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $recordsA = @dns_get_record($host, DNS_A);
            if (is_array($recordsA)) {
                foreach ($recordsA as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                }
            }

            if (defined('DNS_AAAA')) {
                $recordsAAAA = @dns_get_record($host, DNS_AAAA);
                if (is_array($recordsAAAA)) {
                    foreach ($recordsAAAA as $record) {
                        if (!empty($record['ipv6'])) {
                            $ips[] = $record['ipv6'];
                        }
                    }
                }
            }
        } elseif (function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isPublicIp((string) $ip)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function guessMime(string $data): ?string
    {
        if ($data === '') {
            return null;
        }

        $magic = substr($data, 0, 4);

        if ($magic === "\x89PNG") {
            return 'image/png';
        }
        if (strncmp($magic, "\xff\xd8\xff", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($magic, "GIF8", 4) === 0) {
            return 'image/gif';
        }

        return null;
    }
}
