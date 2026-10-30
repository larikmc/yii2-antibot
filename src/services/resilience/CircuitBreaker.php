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
 * - HALF_OPEN: Recovery testing period; lets an atomically leased single probe through to evaluate health.
 *
 * All state mutations (reading, evaluating, transitioning, and saving) are performed atomically
 * under a short-lived file lock to eliminate read-modify-write race conditions across concurrent PHP processes.
 * The lock is NEVER held while the protected service or operation is executing.
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
     *
     * Performs an atomic check-and-lease operation:
     * - If CLOSED: returns true.
     * - If OPEN and recoveryTimeout has elapsed: transitions to HALF_OPEN and grants the probe lease to this process.
     * - If HALF_OPEN: returns true ONLY to the single process that holds the active lease; other processes return false.
     *
     * @param string $service
     * @param int $recoveryTimeout
     * @return bool
     */
    public static function isAvailable(string $service = 'default', int $recoveryTimeout = self::DEFAULT_RECOVERY_TIMEOUT): bool
    {
        return self::atomicStateTransition(function (array &$states) use ($service, $recoveryTimeout): bool {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeLeaseHolder' => '',
            ];

            $state = $info['state'] ?? self::STATE_CLOSED;

            if ($state === self::STATE_CLOSED) {
                return true;
            }

            $now = time();

            if ($state === self::STATE_OPEN) {
                $lastFailure = (int) ($info['lastFailure'] ?? 0);
                if ($now - $lastFailure >= $recoveryTimeout) {
                    // Transition to HALF_OPEN and atomically grant probe lease to this process
                    $info['state'] = self::STATE_HALF_OPEN;
                    $info['probeTimestamp'] = $now;
                    $info['probeLeaseHolder'] = (string) getmypid();
                    $states[$service] = $info;
                    return true; // THIS process won the single probe lease!
                }
                return false; // Circuit is still in recovery backoff
            }

            if ($state === self::STATE_HALF_OPEN) {
                $probeTimestamp = (int) ($info['probeTimestamp'] ?? 0);
                // Protect against thundering herd: only 1 probe permitted per interval
                if ($now - $probeTimestamp < self::HALF_OPEN_PROBE_INTERVAL) {
                    // Another process currently holds the active probe lease; fail-open fallback
                    return false;
                }
                // Lease expired (probing worker died or hung): grant new lease!
                $info['probeTimestamp'] = $now;
                $info['probeLeaseHolder'] = (string) getmypid();
                $states[$service] = $info;
                return true;
            }

            return true;
        });
    }

    /**
     * Records a successful execution, resetting failure counters and closing circuit.
     *
     * @param string $service
     */
    public static function recordSuccess(string $service = 'default'): void
    {
        self::atomicStateTransition(function (array &$states) use ($service): void {
            $states[$service] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeLeaseHolder' => '',
            ];
        });
    }

    /**
     * Records an execution failure, potentially tripping the circuit to OPEN.
     *
     * @param string $service
     * @param int $failureThreshold
     */
    public static function recordFailure(string $service = 'default', int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD): void
    {
        self::atomicStateTransition(function (array &$states) use ($service, $failureThreshold): void {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeLeaseHolder' => '',
            ];
            $info['failures'] = ((int) ($info['failures'] ?? 0)) + 1;
            $info['lastFailure'] = time();

            // If in HALF_OPEN (failed probe) or threshold reached, trip to OPEN
            if ($info['state'] === self::STATE_HALF_OPEN || $info['failures'] >= $failureThreshold) {
                $info['state'] = self::STATE_OPEN;
                $info['probeTimestamp'] = 0;
                $info['probeLeaseHolder'] = '';
            }

            $states[$service] = $info;
        });
    }

    /**
     * Gets the current state of a circuit service ('closed', 'open', 'half_open').
     *
     * @param string $service
     * @return string
     */
    public static function getState(string $service = 'default'): string
    {
        $file = self::resolveStateFile();
        $states = self::loadStatesFromFile($file);
        return $states[$service]['state'] ?? self::STATE_CLOSED;
    }

    /**
     * Resolves the full path to the circuit breaker shared state file.
     *
     * @return string
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
     * Executes an atomic state mutation under a dedicated file lock.
     *
     * Holds the lock ONLY for reading, evaluating, and writing the small JSON payload (microseconds).
     * The lock is NEVER held during the execution of protected client services.
     *
     * @template R
     * @param callable(array<string, array<string, mixed>>&): R $callback
     * @return R
     */
    private static function atomicStateTransition(callable $callback)
    {
        $stateFile = self::resolveStateFile();
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lockFile = $stateFile . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            // Fallback without lock if file cannot be opened
            $states = self::loadStatesFromFile($stateFile);
            $res = $callback($states);
            self::saveStatesToFile($stateFile, $states);
            return $res;
        }

        @flock($fp, LOCK_EX);
        try {
            $states = self::loadStatesFromFile($stateFile);
            $result = $callback($states);
            self::saveStatesToFile($stateFile, $states);
            return $result;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * Loads circuit states from file, safely handling missing or corrupted JSON.
     *
     * @param string $file
     * @return array<string, array<string, mixed>>
     */
    private static function loadStatesFromFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        try {
            $content = @file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Corrupted JSON: safely reset to empty array
        }

        return [];
    }

    /**
     * Saves all circuit states to shared storage.
     *
     * @param string $file
     * @param array<string, array<string, mixed>> $states
     */
    private static function saveStatesToFile(string $file, array $states): void
    {
        if (function_exists('apcu_store')) {
            @apcu_store('__antibot_circuit_states__', $states, 86400);
        }

        try {
            $json = json_encode($states, JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, $json, LOCK_EX);
        } catch (\Throwable) {
            // Never break website on storage failure
        }
    }

    /**
     * Manually resets all circuit states (clears shared file, lock file, APCu, and memory).
     */
    public static function resetAll(): void
    {
        if (function_exists('apcu_delete')) {
            @apcu_delete('__antibot_circuit_states__');
        }

        $file = self::resolveStateFile();
        if (file_exists($file)) {
            @unlink($file);
        }
        $lockFile = $file . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }
}
