<?php

declare(strict_types=1);

namespace larikmc\antibot\services\limiter;

use Yii;

/**
 * Atomic sliding-window rate limiter powered by Redis and Lua script.
 *
 * Runs an atomic sliding-window algorithm in a single round-trip:
 * 1. ZREMRANGEBYSCORE to prune expired timestamps outside the sliding window.
 * 2. ZCARD to get current request count.
 * 3. ZADD to record current timestamp if under limit.
 * 4. EXPIRE to set automatic garbage collection TTL.
 *
 * Falls back to CacheLimiter if Redis connection is unavailable.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RedisLimiter implements RateLimiterInterface
{
    /**
     * @var string ID of the Yii Redis component.
     */
    public string $redisComponent = 'redis';

    private ?CacheLimiter $_fallback = null;

    /**
     * Lua script for atomic sliding window rate limiting.
     */
    private const LUA_SLIDING_WINDOW = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local maxRequests = tonumber(ARGV[3])
local clearBefore = now - window

-- Remove timestamps older than window
redis.call('ZREMRANGEBYSCORE', key, '-inf', clearBefore)

-- Current count in sliding window
local currentRequests = redis.call('ZCARD', key)

if currentRequests < maxRequests then
    -- Add current request timestamp
    redis.call('ZADD', key, now, now .. '-' .. math.random(1000, 9999))
    redis.call('EXPIRE', key, math.ceil(window) * 2)
    return {0, currentRequests + 1}
else
    return {1, currentRequests}
end
LUA;

    /**
     * {@inheritdoc}
     */
    public function check(string $key, int $maxRequests = 60, int $window = 60, int $banDuration = 300): array
    {
        $now = microtime(true);
        $intNow = (int) $now;
        $redis = $this->getRedisClient();

        if ($redis === null) {
            return $this->getFallback()->check($key, $maxRequests, $window, $banDuration);
        }

        $redisKey = 'antibot_rl_z_' . md5($key);
        $banKey = 'antibot_ban_' . md5($key);

        try {
            // Check temporary ban
            $ban = $this->executeRedisCommand($redis, 'GET', [$banKey]);
            if ($ban !== null && $ban !== false && is_numeric($ban) && (int) $ban > $intNow) {
                $banUntil = (int) $ban;
                return [
                    'exceeded' => true,
                    'current' => $maxRequests + 1,
                    'remaining' => 0,
                    'reset' => $banUntil,
                    'retryAfter' => max(1, $banUntil - $intNow),
                ];
            }

            // Execute atomic Lua script
            $result = $this->evalLua($redis, self::LUA_SLIDING_WINDOW, [$redisKey], [$now, $window, $maxRequests]);

            if (!is_array($result) || count($result) < 2) {
                return $this->getFallback()->check($key, $maxRequests, $window, $banDuration);
            }

            $isExceeded = (int) $result[0] === 1;
            $currentCount = (int) $result[1];
            $retryAfter = $isExceeded ? $window : 0;

            if ($isExceeded && $banDuration > 0 && $currentCount >= ($maxRequests * 1.5)) {
                $banUntil = $intNow + $banDuration;
                $this->executeRedisCommand($redis, 'SETEX', [$banKey, $banDuration, $banUntil]);
                $retryAfter = $banDuration;
            }

            return [
                'exceeded' => $isExceeded,
                'current' => $currentCount,
                'remaining' => max(0, $maxRequests - $currentCount),
                'reset' => $intNow + $window,
                'retryAfter' => $retryAfter,
            ];
        } catch (\Throwable $e) {
            Yii::warning('RedisLimiter error, falling back to CacheLimiter: ' . $e->getMessage(), __METHOD__);
            return $this->getFallback()->check($key, $maxRequests, $window, $banDuration);
        }
    }

    /**
     * Resolves active Redis connection.
     */
    protected function getRedisClient(): mixed
    {
        if (Yii::$app !== null && Yii::$app->has($this->redisComponent)) {
            $client = Yii::$app->get($this->redisComponent);
            if ($client !== null) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Evaluates Lua script via yii\redis\Connection or native \Redis.
     */
    protected function evalLua(mixed $client, string $script, array $keys, array $args): mixed
    {
        if (method_exists($client, 'executeCommand')) {
            // yii\redis\Connection
            $params = array_merge([$script, count($keys)], $keys, $args);
            return $client->executeCommand('EVAL', $params);
        }

        if ($client instanceof \Redis) {
            return $client->eval($script, array_merge($keys, $args), count($keys));
        }

        return null;
    }

    /**
     * Executes arbitrary Redis command.
     */
    protected function executeRedisCommand(mixed $client, string $command, array $params = []): mixed
    {
        if (method_exists($client, 'executeCommand')) {
            return $client->executeCommand($command, $params);
        }

        if ($client instanceof \Redis) {
            return call_user_func_array([$client, strtolower($command)], $params);
        }

        return null;
    }

    protected function getFallback(): CacheLimiter
    {
        if ($this->_fallback === null) {
            $this->_fallback = new CacheLimiter();
        }
        return $this->_fallback;
    }
}
