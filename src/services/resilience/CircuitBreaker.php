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
 * Implements the standard Three-State Circuit Breaker pattern with persistent cross-process state:
 * - CLOSED: Normal healthy operation.
 * - OPEN: Tripped due to consecutive failures; requests gracefully fail-open without overhead.
 * - HALF_OPEN: Recovery testing period; lets a single probe through to evaluate health.
 *
 * State is persisted across independent PHP-FPM requests and CLI workers via shared runtime storage,
 * completely independent of the application Cache component.
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
    public const HALF_OPEN_PROBE_INTERVAL = 5;  // seconds between trial probes in HALF_OPEN

    /**
     * In-process local cache with timestamp to prevent redundant file reads during single request.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $processLocalStates = null;
    private static float $lastLocalReadTime = 0.0;

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
     * Handles OPEN -> HALF_OPEN transitions and throttles concurrent trial probes.
     */
    public static function isAvailable(string $service = 'default', int $recoveryTimeout = self::DEFAULT_RECOVERY_TIMEOUT): bool
    {
        $info = self::getServiceInfo($service);
        $state = $info['state'];

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        $now = time();

        if ($state === self::STATE_OPEN) {
            $lastFailure = (int) ($info['lastFailure'] ?? 0);
            if ($now - $lastFailure >= $recoveryTimeout) {
                // Trip to HALF_OPEN to allow trial probe
                $info['state'] = self::STATE_HALF_OPEN;
                $info['probeTimestamp'] = $now;
                self::updateServiceInfo($service, $info);
                return true;
            }
            return false;
        }

        if ($state === self::STATE_HALF_OPEN) {
            $probeTimestamp = (int) ($info['probeTimestamp'] ?? 0);
            // Protect against thundering herd: only 1 probe permitted per interval
            if ($now - $probeTimestamp < self::HALF_OPEN_PROBE_INTERVAL) {
                return false; // Other concurrent requests take safe fallback while probe runs
            }
            $info['probeTimestamp'] = $now;
            self::updateServiceInfo($service, $info);
            return true;
        }

        return true;
    }

    /**
     * Records a successful execution, resetting failure counters and closing circuit.
     */
    public static function recordSuccess(string $service = 'default'): void
    {
        $info = [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'lastFailure' => 0,
            'probeTimestamp' => 0,
        ];
        self::updateServiceInfo($service, $info);
    }

    /**
     * Records an execution failure, potentially tripping the circuit to OPEN.
     */
    public static function recordFailure(string $service = 'default', int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD): void
    {
        $info = self::getServiceInfo($service);
        $info['failures'] = ((int) ($info['failures'] ?? 0)) + 1;
        $info['lastFailure'] = time();

        // If in HALF_OPEN or failures threshold reached, trip to OPEN
        if ($info['state'] === self::STATE_HALF_OPEN || $info['failures'] >= $failureThreshold) {
            $info['state'] = self::STATE_OPEN;
            $info['probeTimestamp'] = 0;
        }

        self::updateServiceInfo($service, $info);
    }

    /**
     * Gets the current state of a circuit service ('closed', 'open', 'half_open').
     */
    public static function getState(string $service = 'default'): string
    {
        $info = self::getServiceInfo($service);
        return $info['state'] ?? self::STATE_CLOSED;
    }

    /**
     * Resolves the full path to the circuit breaker shared state file.
     */
    public static function resolveStateFile(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir . '/antibot_circuits.json';
        }

        if (Yii::$app !== null && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return $dir . '/antibot_circuits.json';
        }

        return sys_get_temp_dir() . '/antibot_circuits.json';
    }

    /**
     * Retrieves service state info, using process memory and shared file.
     *
     * @return array{state: string, failures: int, lastFailure: int, probeTimestamp: int}
     */
    private static function getServiceInfo(string $service): array
    {
        $states = self::loadAllStates();
        return $states[$service] ?? [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'lastFailure' => 0,
            'probeTimestamp' => 0,
        ];
    }

    private static function updateServiceInfo(string $service, array $info): void
    {
        $states = self::loadAllStates();
        $states[$service] = $info;
        self::saveAllStates($states);
    }

    /**
     * Loads all circuit states across workers.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function loadAllStates(): array
    {
        $now = microtime(true);
        if (self::$processLocalStates !== null && ($now - self::$lastLocalReadTime) < 0.25) {
            return self::$processLocalStates;
        }

        if (function_exists('apcu_fetch')) {
            $success = false;
            $cached = apcu_fetch('__antibot_circuit_states__', $success);
            if ($success && is_array($cached)) {
                self::$processLocalStates = $cached;
                self::$lastLocalReadTime = $now;
                return $cached;
            }
        }

        $file = self::resolveStateFile();
        if (!file_exists($file)) {
            self::$processLocalStates = [];
            self::$lastLocalReadTime = $now;
            return [];
        }

        try {
            $content = @file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    self::$processLocalStates = $decoded;
                    self::$lastLocalReadTime = $now;
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Silently fall back to in-process memory
        }

        self::$processLocalStates = self::$processLocalStates ?? [];
        self::$lastLocalReadTime = $now;
        return self::$processLocalStates;
    }

    /**
     * Saves all circuit states to shared cross-process storage.
     *
     * @param array<string, array<string, mixed>> $states
     */
    private static function saveAllStates(array $states): void
    {
        self::$processLocalStates = $states;
        self::$lastLocalReadTime = microtime(true);

        if (function_exists('apcu_store')) {
            @apcu_store('__antibot_circuit_states__', $states, 86400);
        }

        $file = self::resolveStateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $json = json_encode($states, JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, $json, LOCK_EX);
        } catch (\Throwable) {
            // Never break website on storage failure
        }
    }

    /**
     * Manually resets all circuit states (clears shared file, APCu, and memory).
     */
    public static function resetAll(): void
    {
        self::$processLocalStates = [];
        self::$lastLocalReadTime = 0.0;

        if (function_exists('apcu_delete')) {
            @apcu_delete('__antibot_circuit_states__');
        }

        $file = self::resolveStateFile();
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
