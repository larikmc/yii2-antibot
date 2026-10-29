<?php

declare(strict_types=1);

namespace larikmc\antibot\services\behavior;

/**
 * Deterministic Risk Assessment Engine.
 *
 * Combines behavioral anomaly signals into an aggregate risk score (0 - 100).
 * Prevents false positives by ensuring no single isolated signal triggers a block.
 *
 * Thresholds:
 * - 0 - 39:   Low Risk (Normal traffic)
 * - 40 - 69:  Medium Risk (Suspicious, flag for monitoring or challenge in strict mode)
 * - 70 - 100: High Risk (Aggressive automation, challenge or throttle)
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RiskEngine
{
    public const THRESHOLD_LOW = 40;
    public const THRESHOLD_HIGH = 70;

    /**
     * Standard weights for behavioral signals.
     */
    public const DEFAULT_SIGNAL_WEIGHTS = [
        'rapid_navigation' => 25,
        'high_url_diversity' => 25,
        'search_abuse' => 35,
        'error_burst' => 25,
        'repeated_violations' => 40,
        'honey_trap_hit' => 50,
        'missing_accept_header' => 15,
    ];

    /**
     * Computes the total risk score from active signals.
     *
     * @param array<int, string> $activeSignals List of signal keys detected for client.
     * @param array<string, int> $customWeights Optional custom weights map.
     * @return array{score: int, level: string, action: string, signals: array<int, string>}
     */
    public static function evaluate(array $activeSignals, array $customWeights = []): array
    {
        $weights = array_merge(self::DEFAULT_SIGNAL_WEIGHTS, $customWeights);
        $totalScore = 0;
        $matchedSignals = [];

        foreach ($activeSignals as $signal) {
            if (isset($weights[$signal])) {
                $totalScore += $weights[$signal];
                $matchedSignals[] = $signal;
            }
        }

        // Cap at 100
        $score = (int) min(100, $totalScore);

        $level = 'low';
        $action = 'allow';

        if ($score >= self::THRESHOLD_HIGH) {
            $level = 'high';
            // Even at high risk, challenge or throttle rather than permanent ban
            $action = 'challenge';
        } elseif ($score >= self::THRESHOLD_LOW) {
            $level = 'medium';
            $action = 'monitor';
        }

        return [
            'score' => $score,
            'level' => $level,
            'action' => $action,
            'signals' => $matchedSignals,
        ];
    }
}
