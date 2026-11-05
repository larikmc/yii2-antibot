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
    public static function simulateRateLimit(array $events, int $maxRequests, int $windowSeconds = 60, ?string $lang = null): array
    {
        $sampleSize = count($events);
        $isRu = ($lang ?? \larikmc\antibot\services\I18n::getLanguage()) === 'ru';

        if ($sampleSize === 0) {
            return [
                'sufficient' => false,
                'preliminary' => false,
                'sampleSize' => 0,
                'wouldBlock' => 0,
                'impactPercent' => 0.0,
                'affectedIps' => [],
                'affectedRoutes' => [],
                'searchBotsImpacted' => 0,
                'verdict' => 'empty',
                'verdictTitle' => $isRu ? 'Журнал событий пуст' : 'Event Log Empty',
                'notice' => $isRu
                    ? 'В журнале пока нет событий для анализа. Запросы появятся при активности посетителей.'
                    : 'Event log is currently empty. Requests will be recorded as traffic arrives.',
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

        $impactPercent = round(($wouldBlock / $sampleSize) * 100, 1);
        $isPreliminary = ($sampleSize < self::MIN_EVENTS_REQUIRED);

        $verdict = match (true) {
            $impactPercent === 0.0 => 'safe',
            $impactPercent < 3.0 => 'mild',
            $impactPercent < 15.0 => 'moderate',
            default => 'high_risk',
        };

        $verdictTitle = match ($verdict) {
            'safe' => $isRu ? 'Безопасно для посетителей' : 'Safe for Visitors',
            'mild' => $isRu ? 'Минимальное влияние' : 'Mild Impact',
            'moderate' => $isRu ? 'Умеренный риск' : 'Moderate Impact',
            default => $isRu ? 'Высокий риск блокировок' : 'High Risk of False Positives',
        };

        $notice = match ($verdict) {
            'safe' => $isRu 
                ? 'Ни один реальный посетитель или поисковик не превысил бы этот лимит.' 
                : 'No legitimate visitor or crawler would have exceeded this rate limit.',
            'mild' => $isRu 
                ? "Затронуто {$wouldBlock} из {$sampleSize} запросов ({$impactPercent}%). Вероятно отсекаются агрессивные клиенты." 
                : "Affected {$wouldBlock} of {$sampleSize} requests ({$impactPercent}%). Likely filters aggressive clients.",
            'moderate' => $isRu 
                ? "Затронуто {$wouldBlock} запросов ({$impactPercent}% трафика). Проверьте затронутые адреса и URL." 
                : "Affected {$wouldBlock} requests ({$impactPercent}% of traffic). Review affected IPs and routes.",
            default => $isRu 
                ? "ВНИМАНИЕ: Лимит отсекает {$wouldBlock} запросов ({$impactPercent}% всего трафика). Высокий риск ложных блокировок!" 
                : "WARNING: Limit blocks {$wouldBlock} requests ({$impactPercent}% of all traffic). High risk of false positives!",
        };

        if ($isPreliminary) {
            $notice .= $isRu
                ? " (Предварительная оценка на выборке из {$sampleSize} событий)."
                : " (Preliminary estimate on a sample of {$sampleSize} events).";
        }

        return [
            'sufficient' => !$isPreliminary,
            'preliminary' => $isPreliminary,
            'sampleSize' => $sampleSize,
            'wouldBlock' => $wouldBlock,
            'impactPercent' => $impactPercent,
            'affectedIps' => $affectedIps,
            'affectedRoutes' => $affectedRoutes,
            'searchBotsImpacted' => 0, // Legitimate search bots are strictly protected
            'verdict' => $verdict,
            'verdictTitle' => $verdictTitle,
            'notice' => $notice,
        ];
    }

    /**
     * Simulates blocking a specific User-Agent pattern.
     *
     * @param array<int, array<string, mixed>> $events
     * @param string $uaPattern Substring or regex pattern.
     * @param string|null $lang Language code ('ru' or 'en').
     * @return array<string, mixed>
     */
    public static function simulateUserAgentBlock(array $events, string $uaPattern, ?string $lang = null): array
    {
        $sampleSize = count($events);
        $isRu = ($lang ?? \larikmc\antibot\services\I18n::getLanguage()) === 'ru';

        if ($sampleSize === 0) {
            return [
                'sufficient' => false,
                'preliminary' => false,
                'sampleSize' => 0,
                'wouldBlock' => 0,
                'impactPercent' => 0.0,
                'affectedIps' => [],
                'affectedRoutes' => [],
                'searchBotsImpacted' => 0,
                'verdict' => 'empty',
                'verdictTitle' => $isRu ? 'Журнал событий пуст' : 'Event Log Empty',
                'notice' => $isRu
                    ? 'В журнале пока нет событий для анализа.'
                    : 'Event log is currently empty.',
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

        $impactPercent = round(($wouldBlock / $sampleSize) * 100, 1);
        $isPreliminary = ($sampleSize < self::MIN_EVENTS_REQUIRED);

        $verdict = 'safe';
        if ($searchBotsImpacted > 0) {
            $verdict = 'high_risk';
            $verdictTitle = $isRu ? 'Опасно: блокировка поисковиков' : 'Danger: Blocks Search Engines';
            $notice = $isRu
                ? "ОПАСНОСТЬ: Шаблон блокирует легитимных поисковых роботов ({$searchBotsImpacted} запросов Google/Яндекс)!"
                : "DANGER: Pattern blocks genuine search crawlers ({$searchBotsImpacted} Google/Yandex requests)!";
        } elseif ($impactPercent > 20.0) {
            $verdict = 'high_risk';
            $verdictTitle = $isRu ? 'Высокий риск' : 'High Risk';
            $notice = $isRu
                ? "Предупреждение: Правило затрагивает {$wouldBlock} из {$sampleSize} запросов ({$impactPercent}% трафика)."
                : "Warning: Rule affects {$wouldBlock} of {$sampleSize} requests ({$impactPercent}% of traffic).";
        } elseif ($wouldBlock > 0) {
            $verdict = 'mild';
            $verdictTitle = $isRu ? 'Правило активно' : 'Rule Active';
            $notice = $isRu
                ? "Правило отсекает {$wouldBlock} запросов ({$impactPercent}% трафика) без ущерба для поисковиков."
                : "Rule filters {$wouldBlock} requests ({$impactPercent}% of traffic) with zero search bot impact.";
        } else {
            $verdict = 'safe';
            $verdictTitle = $isRu ? 'Совпадений не обнаружено' : 'No Matches Found';
            $notice = $isRu
                ? "В текущем журнале не найдено клиентов с User-Agent, содержащим '{$uaPattern}'."
                : "No clients with User-Agent containing '{$uaPattern}' were found in recent log.";
        }

        if ($isPreliminary) {
            $notice .= $isRu
                ? " (Выборка: {$sampleSize} событий)."
                : " (Sample: {$sampleSize} events).";
        }

        return [
            'sufficient' => !$isPreliminary,
            'preliminary' => $isPreliminary,
            'sampleSize' => $sampleSize,
            'wouldBlock' => $wouldBlock,
            'impactPercent' => $impactPercent,
            'affectedIps' => $affectedIps,
            'affectedRoutes' => $affectedRoutes,
            'searchBotsImpacted' => $searchBotsImpacted,
            'verdict' => $verdict,
            'verdictTitle' => $verdictTitle,
            'notice' => $notice,
        ];
    }
}
