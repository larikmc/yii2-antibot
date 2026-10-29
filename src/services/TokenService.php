<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * Cryptographic token service for signed cookies, JS challenges, and anti-tamper verification.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class TokenService
{
    /**
     * @var string Cookie name for verified human visitors.
     */
    public const COOKIE_NAME = 'antibot_vtoken';

    /**
     * @var int Default validity in seconds (7 days).
     */
    public const DEFAULT_TTL = 604800;

    /**
     * Generates a cryptographically signed verification cookie value.
     *
     * @param string $ip Client IP.
     * @param string $userAgent Client User-Agent.
     * @param string $secretKey Secret key.
     * @param int $ttl Validity duration in seconds.
     * @return string Signed token string.
     */
    public static function generateToken(string $ip, string $userAgent, string $secretKey, int $ttl = self::DEFAULT_TTL): string
    {
        $time = time();
        $expire = $time + $ttl;
        $ipPrefix = self::extractIpPrefix($ip);
        $uaHash = substr(hash('sha256', $userAgent), 0, 16);

        $payload = sprintf('%d|%d|%s|%s', $time, $expire, $ipPrefix, $uaHash);
        $hash = hash_hmac('sha256', $payload, $secretKey);

        return base64_encode($payload . '|' . substr($hash, 0, 32));
    }

    /**
     * Validates a signed verification token.
     *
     * @param string $token Token from cookie or header.
     * @param string $ip Current client IP.
     * @param string $userAgent Current client User-Agent.
     * @param string $secretKey Secret key.
     * @return bool True if valid and not expired, false otherwise.
     */
    public static function validateToken(string $token, string $ip, string $userAgent, string $secretKey): bool
    {
        if (trim($token) === '') {
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return false;
        }

        [$timeStr, $expireStr, $tokenIpPrefix, $tokenUaHash, $signature] = $parts;
        $expire = (int) $expireStr;

        // Check expiration
        if (time() > $expire) {
            return false;
        }

        // Check IP subnet prefix
        $currentIpPrefix = self::extractIpPrefix($ip);
        if ($tokenIpPrefix !== $currentIpPrefix) {
            return false;
        }

        // Check UA hash
        $currentUaHash = substr(hash('sha256', $userAgent), 0, 16);
        if (!hash_equals($tokenUaHash, $currentUaHash)) {
            return false;
        }

        // Verify HMAC signature
        $payload = sprintf('%s|%s|%s|%s', $timeStr, $expireStr, $tokenIpPrefix, $tokenUaHash);
        $expectedHash = substr(hash_hmac('sha256', $payload, $secretKey), 0, 32);

        return hash_equals($expectedHash, $signature);
    }

    /**
     * Generates a lightweight client-side Proof-of-Work / JS challenge seed.
     *
     * @param string $ip
     * @param string $secretKey
     * @return array{seed: string, target: string, timestamp: int}
     */
    public static function generateJsChallenge(string $ip, string $secretKey): array
    {
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        $seed = sprintf('%s|%d|%s', $ip, $timestamp, $random);
        $target = hash_hmac('sha256', $seed, $secretKey);

        return [
            'seed' => $seed,
            'target' => $target,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Verifies the answer to a JS challenge.
     *
     * @param string $seed
     * @param string $answer
     * @param string $secretKey
     * @param int $maxAge Max age in seconds (default 120s).
     * @return bool
     */
    public static function verifyJsChallenge(string $seed, string $answer, string $secretKey, int $maxAge = 120): bool
    {
        $parts = explode('|', $seed);
        if (count($parts) !== 3) {
            return false;
        }

        $timestamp = (int) $parts[1];
        if (abs(time() - $timestamp) > $maxAge) {
            return false;
        }

        $expectedTarget = hash_hmac('sha256', $seed, $secretKey);

        return hash_equals($expectedTarget, $answer);
    }

    /**
     * Extracts /24 prefix for IPv4 or /48 prefix for IPv6 to allow minor dynamic IP shifts.
     */
    protected static function extractIpPrefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return count($parts) === 4 ? sprintf('%s.%s.%s', $parts[0], $parts[1], $parts[2]) : $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return count($parts) >= 3 ? sprintf('%s:%s:%s', $parts[0], $parts[1], $parts[2]) : $ip;
        }

        return $ip;
    }
}
