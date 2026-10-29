<?php

declare(strict_types=1);

namespace larikmc\antibot\services\limiter;

/**
 * Interface for rate limiting backend adapters.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
interface RateLimiterInterface
{
    /**
     * Checks if the given key has exceeded the allowed rate limit.
     *
     * @param string $key Identifier (IP or IP+route).
     * @param int $maxRequests Maximum requests allowed.
     * @param int $window Window duration in seconds.
     * @param int $banDuration Ban duration in seconds if threshold is repeatedly exceeded.
     * @return array{
     *     exceeded: bool,
     *     current: int,
     *     remaining: int,
     *     reset: int,
     *     retryAfter: int
     * }
     */
    public function check(string $key, int $maxRequests = 60, int $window = 60, int $banDuration = 300): array;
}
