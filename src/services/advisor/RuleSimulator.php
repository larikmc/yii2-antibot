<?php

declare(strict_types=1);

namespace larikmc\antibot\services\advisor;

use larikmc\antibot\services\BotDetector;

/**
 * Rule Simulator: "What-If" offline policy simulation engine.
 *
 * Replays historical JSONL traffic events against hypothetical rules and limits
 * to predict collateral impact, false positive risks, and mitigation efficiency
 * before any real configuration changes are applied to production.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RuleSimulator
{
    public const MIN_EVENTS_REQUIRED = 20;

    /**
     * Checks whether there is sufficient traffic history to perform a meaningful simulation.
     *
     * @param array<int, array<string, mixed>> $events
     * @param int $minRequired
     * @return bool
     */
    public static function hasSufficientData(array $events, int $minRequired = self::MIN_EVENTS_REQUIRED): bool
    {
        return count($events) >= $minRequired;
    }

    /**
     * Simulates changing the Rate Limiter threshold (requests per window).
     *
     * @param array<int, array<string, mixed>> $events
     * @param int $maxRequests
     * @param int $windowSeconds
     * @return array{
     *     sufficient: bool,
     *     sampleSize: int,
     *     wouldBlock: int,
     *     impactPercent: float,
     *     affectedIps: array<string, int>,
     *     affectedRoutes: array<string, int>,
     *     searchBotsImpacted: int,
     *     verdict: string,
     *     notice: string
     * }
     */
    public static function simulateRateLimit(array $events, int $maxRequests, int $windowSeconds = 60): array
    {
        $sampleSize = count($events);
        if ($sampleSize < self::MIN_EVENTS_REQUIRED) {
            return [
                'sufficient' => false,
                'sampleSize' => $sampleSize,
                'wouldBlock' => 0,
                'impactPercent' => 0.0,
                'affectedIps' => [],
                'affectedRoutes' => [],
                'searchBotsImpacted' => 0,
                'verdict' => 'insufficient_data',
                'notice' => 'Недостаточно данных для достоверной симуляции (требуется от ' . self::MIN_EVENTS_REQUIRED . ' событий в журнале).',
            ];
        }

        // Sort events chronologically ascending for replay
        $sorted = $events;
        usort($sorted, static fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $ipBuckets = [];
        $wouldBlock = 0;
        $affectedIps = [];
        $affectedRoutes = [];

        foreach ($sorted as $e) {
            $ip = $e['ip'] ?? '127.0.0.1';
            $ts = (int) ($e['timestamp'] ?? time());
            $route = $e['route'] ?? ($e['url'] ?? '/');
            $ua = $e['userAgent'] ?? '';

            // Verified search engines are always exempt from rate limit blocking
            if (BotDetector::detectSearchBot($ua) !== null && ($e['action'] ?? '') === 'allowed_search_bot') {
                continue;
            }

            if (!isset($ipBuckets[$ip])) {
                $ipBuckets[$ip] = [];
            }

            // Prune timestamps outside rolling window
            $cutoff = $ts - $windowSeconds;
            $ipBuckets[$ip] = array_values(array_filter($ipBuckets[$ip], static fn($t) => $t > $cutoff));

            if (count($ipBuckets[$ip]) >= $maxRequests) {
                $wouldBlock++;
                $affectedIps[$ip] = ($affectedIps[$ip] ?? 0) + 1;
                $parsedRoute = parse_url($route, PHP_URL_PATH) ?? $route;
                $affectedRoutes[$parsedRoute] = ($affectedRoutes[$parsedRoute] ?? 0) + 1;
            } else {
                $ipBuckets[$ip][] = $ts;
            }
        }

        $impactPercent = round(($wouldBlock / $sampleSize) * 100, 2);

        $verdict = match (true) {
            $impactPercent === 0.0 => 'safe',
            $impactPercent < 3.0 => 'mild',
            $impactPercent < 15.0 => 'moderate',
            default => 'high_risk',
        };

        $notice = match ($verdict) {
            'safe' => 'Безопасно: ни один реальный посетитель не превысил бы этот лимит.',
            'mild' => "Мягкое влияние: затронуто {$impactPercent}% запросов (вероятно агрессивные клиенты).",
            'moderate' => "Умеренное влияние: затронуто {$impactPercent}% трафика. Проверьте список затронутых URL.",
            default => "ВНИМАНИЕ: Высокий риск ложных блокировок! Лимит отсекает {$impactPercent}% всех запросов.",
        };

        return [
            'sufficient' => true,
            'sampleSize' => $sampleSize,
            'wouldBlock' => $wouldBlock,
            'impactPercent' => $impactPercent,
            'affectedIps' => $affectedIps,
            'affectedRoutes' => $affectedRoutes,
            'searchBotsImpacted' => 0, // Legitimate search bots are strictly protected
            'verdict' => $verdict,
            'notice' => $notice,
        ];
    }

    /**
     * Simulates blocking a specific User-Agent pattern.
     *
     * @param array<int, array<string, mixed>> $events
     * @param string $uaPattern Substring or regex pattern.
     * @return array{
     *     sufficient: bool,
     *     sampleSize: int,
     *     wouldBlock: int,
     *     impactPercent: float,
     *     affectedIps: array<string, int>,
     *     affectedRoutes: array<string, int>,
     *     searchBotsImpacted: int,
     *     verdict: string,
     *     notice: string
     * }
     */
    public static function simulateUserAgentBlock(array $events, string $uaPattern): array
    {
        $sampleSize = count($events);
        if ($sampleSize < self::MIN_EVENTS_REQUIRED) {
            return [
                'sufficient' => false,
                'sampleSize' => $sampleSize,
                'wouldBlock' => 0,
                'impactPercent' => 0.0,
                'affectedIps' => [],
                'affectedRoutes' => [],
                'searchBotsImpacted' => 0,
                'verdict' => 'insufficient_data',
                'notice' => 'Недостаточно данных для достоверной симуляции.',
            ];
        }

        $wouldBlock = 0;
        $affectedIps = [];
        $affectedRoutes = [];
        $searchBotsImpacted = 0;

        foreach ($events as $e) {
            $ua = (string) ($e['userAgent'] ?? '');
            $ip = $e['ip'] ?? '127.0.0.1';
            $route = $e['route'] ?? ($e['url'] ?? '/');

            $matches = stripos($ua, $uaPattern) !== false;
            if (!$matches && @preg_match('#' . $uaPattern . '#i', $ua)) {
                $matches = true;
            }

            if ($matches) {
                // Check if genuine search bot would be affected
                if (BotDetector::detectSearchBot($ua) !== null && ($e['action'] ?? '') === 'allowed_search_bot') {
                    $searchBotsImpacted++;
                }

                $wouldBlock++;
                $affectedIps[$ip] = ($affectedIps[$ip] ?? 0) + 1;
                $parsedRoute = parse_url($route, PHP_URL_PATH) ?? $route;
                $affectedRoutes[$parsedRoute] = ($affectedRoutes[$parsedRoute] ?? 0) + 1;
            }
        }

        $impactPercent = round(($wouldBlock / $sampleSize) * 100, 2);

        $verdict = 'safe';
        if ($searchBotsImpacted > 0) {
            $verdict = 'high_risk';
            $notice = "ОПАСНОСТЬ: Правило блокирует легитимные поисковики ({$searchBotsImpacted} запросов Google/Yandex)!";
        } elseif ($impactPercent > 20.0) {
            $verdict = 'high_risk';
            $notice = "Предупреждение: Правило затрагивает {$impactPercent}% трафика.";
        } else {
            $notice = "Правило отсекает {$wouldBlock} запросов ({$impactPercent}% трафика) без вреда для поисковиков.";
        }

        return [
            'sufficient' => true,
            'sampleSize' => $sampleSize,
            'wouldBlock' => $wouldBlock,
            'impactPercent' => $impactPercent,
            'affectedIps' => $affectedIps,
            'affectedRoutes' => $affectedRoutes,
            'searchBotsImpacted' => $searchBotsImpacted,
            'verdict' => $verdict,
            'notice' => $notice,
        ];
    }
}
