<?php

declare(strict_types=1);

namespace larikmc\antibot\services\trap;

/**
 * Service managing robots.txt Honeypot Disallow Trap.
 *
 * Rogue crawlers, scrapers and vulnerability scanners proactively probe Disallow paths
 * found in robots.txt, whereas compliant search engines (Google, Yandex, Bing) never visit them.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RobotsTrapService
{
    public const DEFAULT_ROUTE = '/antibot-trap-disallow';

    /**
     * Determines whether the given requested URI path matches the configured robots.txt trap route.
     *
     * @param string $pathInfo Current URI path
     * @param string $trapRoute Configured trap route
     * @return bool
     */
    public static function isTrapHit(string $pathInfo, string $trapRoute): bool
    {
        $normalizedPath = trim($pathInfo, '/');
        $normalizedTrap = trim($trapRoute, '/');

        if ($normalizedTrap === '' || $normalizedPath === '') {
            return false;
        }

        return $normalizedPath === $normalizedTrap || str_starts_with($normalizedPath, $normalizedTrap . '/');
    }

    /**
     * Generates a recommended robots.txt snippet for website administrators.
     *
     * @param string $trapRoute
     * @return string
     */
    public static function getRobotsTxtSnippet(string $trapRoute = self::DEFAULT_ROUTE): string
    {
        $cleanRoute = '/' . trim($trapRoute, '/') . '/';
        return "# AntiBot Honeypot Disallow Trap (Rogue crawlers only)\nUser-agent: *\nDisallow: {$cleanRoute}\n";
    }
}
