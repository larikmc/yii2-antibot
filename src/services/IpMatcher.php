<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

/**
 * High-performance IP and CIDR subnet matcher.
 *
 * Supports single IPv4/IPv6 addresses and CIDR notation (e.g. 192.168.1.0/24).
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class IpMatcher
{
    /**
     * Checks if a given IP matches any of the entries in an IP list.
     *
     * @param string $ip Client IP address.
     * @param array<int, string> $ipList List of IPs or CIDR subnets.
     * @return bool True if matched, false otherwise.
     */
    public static function match(string $ip, array $ipList): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        foreach ($ipList as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            if (str_contains($entry, '/')) {
                if (self::matchCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches an IP address against a CIDR block (IPv4 or IPv6).
     *
     * @param string $ip
     * @param string $cidr e.g. "192.168.0.0/16" or "2001:db8::/32"
     * @return bool
     */
    public static function matchCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = $parts;
        $mask = (int) $mask;

        // IPv4 check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }
            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($mask === 0) {
                return true;
            }

            $netmask = ~((1 << (32 - $mask)) - 1);

            return ($ipLong & $netmask) === ($subnetLong & $netmask);
        }

        // IPv6 check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            if ($mask < 0 || $mask > 128) {
                return false;
            }

            $ipPacked = inet_pton($ip);
            $subnetPacked = inet_pton($subnet);

            if ($ipPacked === false || $subnetPacked === false) {
                return false;
            }

            $bytes = (int) ($mask / 8);
            $bits = $mask % 8;

            if ($bytes > 0 && substr($ipPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
                return false;
            }

            if ($bits > 0) {
                $bitmask = chr(0xFF << (8 - $bits));
                if ((ord($ipPacked[$bytes]) & ord($bitmask)) !== (ord($subnetPacked[$bytes]) & ord($bitmask))) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Validates whether a string is a valid IPv4, IPv6, or CIDR notation.
     */
    public static function isValidIpOrCidr(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry, 2);
            if (count($parts) !== 2) {
                return false;
            }
            [$ip, $mask] = $parts;
            if (!is_numeric($mask)) {
                return false;
            }
            $mask = (int) $mask;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mask >= 0 && $mask <= 32;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $mask >= 0 && $mask <= 128;
            }
            return false;
        }

        return (bool) filter_var($entry, FILTER_VALIDATE_IP);
    }
}
