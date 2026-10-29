<?php

declare(strict_types=1);

namespace larikmc\antibot\services\resilience;

use Yii;

/**
 * Self-Protection Circuit Breaker.
 *
 * Protects the host website and PHP-FPM worker pools from hanging or crashing
 * in the event of storage I/O failures, Redis outages, or caching server timeouts.
 *
 * Implements the standard Three-State Circuit Breaker pattern:
 * - CLOSED: Normal healthy operation.
 * - OPEN: Tripped due to consecutive failures; requests gracefully fail-open without overhead.
 * - HALF_OPEN: Recovery testing period; lets a single probe through to evaluate health.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    public const DEFAULT_FAILURE_THRESHOLD = 5;
    public const DEFAULT_RECOVERY_TIMEOUT = 30; // seconds

    /**
     * In-memory circuit state cache per process.
     *
     * @var array<string, array{state: string, failures: int, lastFailure: int}>
     */
    private static array $states = [];

    /**
     * Executes an operation protected by the Circuit Breaker.
     *
     * @template T
     * @param callable(): T $operation Target function to execute.
     * @param callable(\Throwable|null): T $fallback Graceful fallback when circuit is open or failed.
     * @param string $service Service identifier ('cache', 'redis', 'storage').
     * @param int $failureThreshold Max consecutive failures before tripping.
     * @param int $recoveryTimeout Seconds to remain open before half-open probe.
     * @return T
     */
    public static function execute(
        callable $operation,
        callable $fallback,
        string $service = 'default',
        int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        int $recoveryTimeout = self::DEFAULT_RECOVERY_TIMEOUT
    ) {
        if (!self::isAvailable($service, $recoveryTimeout)) {
            // Circuit is OPEN: bypass operation and immediately return fallback
            return $fallback(null);
        }

        try {
            $result = $operation();
            self::recordSuccess($service);
            return $result;
        } catch (\Throwable $e) {
            self::recordFailure($service, $failureThreshold);
            if (Yii::$app !== null) {
                Yii::warning(
                    sprintf('CircuitBreaker caught failure in [%s]: %s', $service, $e->getMessage()),
                    __METHOD__
                );
            }
            return $fallback($e);
        }
    }

    /**
     * Checks if the protected service is available for execution.
     */
    public static function isAvailable(string $service = 'default', int $recoveryTimeout = self::DEFAULT_RECOVERY_TIMEOUT): bool
    {
        $state = self::getState($service);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $lastFailure = self::$states[$service]['lastFailure'] ?? 0;
            if (time() - $lastFailure >= $recoveryTimeout) {
                // Trip to HALF_OPEN to probe recovery
                self::$states[$service]['state'] = self::STATE_HALF_OPEN;
                return true;
            }
            return false;
        }

        // HALF_OPEN: allow trial probe
        return true;
    }

    /**
     * Records a successful execution, resetting failure counters and closing circuit.
     */
    public static function recordSuccess(string $service = 'default'): void
    {
        self::$states[$service] = [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'lastFailure' => 0,
        ];
    }

    /**
     * Records an execution failure, potentially tripping the circuit to OPEN.
     */
    public static function recordFailure(string $service = 'default', int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD): void
    {
        if (!isset(self::$states[$service])) {
            self::$states[$service] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
            ];
        }

        self::$states[$service]['failures']++;
        self::$states[$service]['lastFailure'] = time();

        if (self::$states[$service]['failures'] >= $failureThreshold) {
            self::$states[$service]['state'] = self::STATE_OPEN;
        }
    }

    /**
     * Gets the current state of a circuit service.
     */
    public static function getState(string $service = 'default'): string
    {
        return self::$states[$service]['state'] ?? self::STATE_CLOSED;
    }

    /**
     * Manually resets all circuit states (useful for testing or after administrative resolution).
     */
    public static function resetAll(): void
    {
        self::$states = [];
    }
}
