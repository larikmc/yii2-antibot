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
 * Safety & Atomicity Guarantees:
 * - All state mutations execute under a non-blocking flock with short bounded retries (max 5ms).
 * - Never executes un-locked mutations on failure; safely falls back to read-only fail-open mode.
 * - Single-probe lease with generation token in HALF_OPEN protects against delayed probe collisions.
 * - Independent of the application Cache subsystem.
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
        $leaseToken = null;
        if (!self::isAvailable($service, $recoveryTimeout, $leaseToken)) {
            // Circuit is OPEN: bypass operation and immediately return fallback
            return $fallback(null);
        }

        try {
            $result = $operation();
            self::recordSuccess($service, $leaseToken);
            return $result;
        } catch (\Throwable $e) {
            self::recordFailure($service, $failureThreshold, $leaseToken);
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
     * - If OPEN and recoveryTimeout has elapsed: transitions to HALF_OPEN and atomically grants probe lease.
     * - If HALF_OPEN: returns true ONLY to the single process that holds the active lease; other processes return false.
     *
     * @param string $service
     * @param int $recoveryTimeout
     * @param string|null $leaseToken Output parameter receiving the lease token if probe is granted.
     * @return bool
     */
    public static function isAvailable(
        string $service = 'default',
        int $recoveryTimeout = self::DEFAULT_RECOVERY_TIMEOUT,
        ?string &$leaseToken = null
    ): bool {
        $leaseToken = null;
        $capturedToken = null;

        $res = self::atomicStateTransition(function (array &$states) use ($service, $recoveryTimeout, &$capturedToken): bool {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeToken' => '',
                'generation' => 1,
            ];

            $state = $info['state'] ?? self::STATE_CLOSED;

            if ($state === self::STATE_CLOSED) {
                return true;
            }

            $now = time();

            if ($state === self::STATE_OPEN) {
                $lastFailure = (int) ($info['lastFailure'] ?? 0);
                if ($now - $lastFailure >= $recoveryTimeout) {
                    // Transition to HALF_OPEN and atomically grant probe lease
                    $token = bin2hex(random_bytes(8));
                    $info['state'] = self::STATE_HALF_OPEN;
                    $info['probeTimestamp'] = $now;
                    $info['probeToken'] = $token;
                    $info['generation'] = ((int) ($info['generation'] ?? 0)) + 1;
                    $states[$service] = $info;
                    $capturedToken = $token;
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
                // Previous probing worker timed out or died: issue fresh lease!
                $token = bin2hex(random_bytes(8));
                $info['probeTimestamp'] = $now;
                $info['probeToken'] = $token;
                $info['generation'] = ((int) ($info['generation'] ?? 0)) + 1;
                $states[$service] = $info;
                $capturedToken = $token;
                return true;
            }

            return true;
        });

        $leaseToken = $capturedToken;
        return $res;
    }

    /**
     * Records a successful execution, resetting failure counters and closing circuit.
     * Validates generation lease token to prevent delayed old probes from closing a newly opened circuit.
     *
     * @param string $service
     * @param string|null $leaseToken
     */
    public static function recordSuccess(string $service = 'default', ?string $leaseToken = null): void
    {
        self::atomicStateTransition(function (array &$states) use ($service, $leaseToken): void {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeToken' => '',
                'generation' => 1,
            ];

            // In HALF_OPEN, only the active lease holder is allowed to close the circuit
            if ($info['state'] === self::STATE_HALF_OPEN) {
                if ($leaseToken !== null && !empty($info['probeToken']) && $leaseToken !== $info['probeToken']) {
                    // Stale or expired probe: ignore
                    return;
                }
            }

            $states[$service] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeToken' => '',
                'generation' => ((int) ($info['generation'] ?? 0)) + 1,
            ];
        });
    }

    /**
     * Records an execution failure, potentially tripping the circuit to OPEN.
     *
     * @param string $service
     * @param int $failureThreshold
     * @param string|null $leaseToken
     */
    public static function recordFailure(
        string $service = 'default',
        int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        ?string $leaseToken = null
    ): void {
        self::atomicStateTransition(function (array &$states) use ($service, $failureThreshold, $leaseToken): void {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeToken' => '',
                'generation' => 1,
            ];

            if ($info['state'] === self::STATE_HALF_OPEN) {
                if ($leaseToken !== null && !empty($info['probeToken']) && $leaseToken !== $info['probeToken']) {
                    // Stale probe failure: ignore
                    return;
                }
            }

            $info['failures'] = ((int) ($info['failures'] ?? 0)) + 1;
            $info['lastFailure'] = time();

            // If in HALF_OPEN (failed trial probe) or threshold reached, trip to OPEN
            if ($info['state'] === self::STATE_HALF_OPEN || $info['failures'] >= $failureThreshold) {
                $info['state'] = self::STATE_OPEN;
                $info['probeTimestamp'] = 0;
                $info['probeToken'] = '';
                $info['generation'] = ((int) ($info['generation'] ?? 0)) + 1;
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
     * Executes an atomic state mutation under a dedicated file lock with non-blocking retries.
     *
     * If the lock cannot be acquired within 5ms:
     * - Strictly avoids performing un-locked writes that could corrupt shared state!
     * - Falls back to safe in-memory evaluation (fail-open mode) without disrupting the website.
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
            // Cannot open lock file (e.g. read-only filesystem or permissions error):
            // Strictly do NOT perform an unlocked write! Evaluate read-only and fail-open.
            if (Yii::$app !== null) {
                Yii::warning('CircuitBreaker could not open lock file: ' . $lockFile, __METHOD__);
            }
            $states = self::loadStatesFromFile($stateFile);
            return $callback($states);
        }

        // Bounded non-blocking lock (max 5 retries x 1ms = 5ms max wait)
        $locked = false;
        for ($retry = 0; $retry < 5; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(1000); // 1ms
        }

        if (!$locked) {
            @fclose($fp);
            // Lock busy: fail open safely without mutating shared file
            $states = self::loadStatesFromFile($stateFile);
            return $callback($states);
        }

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
            // Corrupted JSON: safely reset to empty array without crashing
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
