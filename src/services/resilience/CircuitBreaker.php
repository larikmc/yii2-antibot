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
     * - If CLOSED: returns true immediately without lock overhead.
     * - If OPEN and recoveryTimeout has elapsed: atomically transitions to HALF_OPEN and grants probe lease under flock.
     * - If HALF_OPEN: returns true ONLY to the single process that successfully acquires the active lease under lock.
     * - If synchronization cannot be acquired: strictly avoids granting probe lease (fail-safe policy).
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

        // 1. Read-only fast evaluation (zero lock overhead for normal healthy traffic)
        $stateFile = self::resolveStateFile();
        $states = self::loadStatesFromFile($stateFile);
        $info = $states[$service] ?? [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'lastFailure' => 0,
            'probeTimestamp' => 0,
            'probeToken' => '',
            'generation' => 1,
        ];

        $state = (string) ($info['state'] ?? self::STATE_CLOSED);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        $now = time();

        if ($state === self::STATE_OPEN) {
            $lastFailure = (int) ($info['lastFailure'] ?? 0);
            if ($now - $lastFailure < $recoveryTimeout) {
                // Recovery backoff has not elapsed yet: unavailable
                return false;
            }
            // Timeout elapsed: eligible to attempt acquiring exclusive trial probe
        } elseif ($state === self::STATE_HALF_OPEN) {
            $probeTimestamp = (int) ($info['probeTimestamp'] ?? 0);
            if ($now - $probeTimestamp < self::HALF_OPEN_PROBE_INTERVAL) {
                // Another worker holds active probe lease: unavailable
                return false;
            }
            // Previous probe timed out or died: eligible to claim fresh lease
        }

        // 2. Atomic state transition: MUST acquire exclusive lock to claim lease
        $capturedToken = null;
        $transitionResult = self::atomicStateTransition(function (array &$states) use ($service, $recoveryTimeout, $now, &$capturedToken): bool {
            $info = $states[$service] ?? [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'lastFailure' => 0,
                'probeTimestamp' => 0,
                'probeToken' => '',
                'generation' => 1,
            ];

            $currentState = (string) ($info['state'] ?? self::STATE_CLOSED);

            if ($currentState === self::STATE_CLOSED) {
                return true;
            }

            if ($currentState === self::STATE_OPEN) {
                $lastFailure = (int) ($info['lastFailure'] ?? 0);
                if ($now - $lastFailure < $recoveryTimeout) {
                    return false;
                }
            } elseif ($currentState === self::STATE_HALF_OPEN) {
                $probeTimestamp = (int) ($info['probeTimestamp'] ?? 0);
                if ($now - $probeTimestamp < self::HALF_OPEN_PROBE_INTERVAL) {
                    return false;
                }
            }

            // Exclusively claim HALF_OPEN trial probe under the acquired lock!
            $token = bin2hex(random_bytes(8));
            $info['state'] = self::STATE_HALF_OPEN;
            $info['probeTimestamp'] = $now;
            $info['probeToken'] = $token;
            $info['generation'] = ((int) ($info['generation'] ?? 0)) + 1;
            $states[$service] = $info;
            $capturedToken = $token;
            return true;
        });

        if ($transitionResult === true && $capturedToken !== null) {
            $leaseToken = $capturedToken;
            return true;
        }

        // Lock could not be acquired or someone else claimed the lease:
        // Do NOT grant probeToken, do NOT allow execution!
        return false;
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
     * - Strictly does NOT execute a mutating callback without an exclusive lock!
     * - Avoids un-locked writes and avoids issuing un-synchronized probe leases.
     * - Returns null indicating failure to synchronize.
     *
     * @template R
     * @param callable(array<string, array<string, mixed>>&): R $callback
     * @return R|null
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
            // Strictly do NOT perform an unlocked write or execute mutating callback!
            if (Yii::$app !== null) {
                Yii::warning('CircuitBreaker could not open lock file: ' . $lockFile, __METHOD__);
            }
            return null;
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
            // Lock busy: strictly fail-safe without running mutating callback
            return null;
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
