<?php

declare(strict_types=1);

namespace larikmc\antibot\services\advisor;

use larikmc\antibot\services\BotDetector;

/**
 * Deterministic Local Heuristic Rule Advisor.
 *
 * 100% on-premise heuristic analysis engine that examines recent traffic logs
 * and generates actionable tuning suggestions for website security rules.
 * Does not make external network calls or require third-party AI/ML services.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RuleAdvisor
{
    /**
     * Analyzes event history and current settings to produce security recommendations.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<string, mixed> $currentSettings
     * @return array<int, array{
     *     id: string,
     *     severity: 'info'|'warning'|'danger',
     *     title: string,
     *     description: string,
     *     suggestedConfig: array<string, mixed>,
     *     reason: string
     * }>
     */
    public static function generateRecommendations(array $events, array $currentSettings): array
    {
        $recommendations = [];

        if (count($events) < 5) {
            return $recommendations;
        }

        // Aggregate statistics
        $ipCounts = [];
        $ip404Counts = [];
        $routeCounts = [];
        $aiTrainingCount = 0;
        $fakeBotCount = 0;
        $throttledCount = 0;

        foreach ($events as $e) {
            $ip = (string) ($e['ip'] ?? '');
            $action = (string) ($e['action'] ?? '');
            $reason = (string) ($e['reason'] ?? '');
            $ua = (string) ($e['userAgent'] ?? '');
            $url = (string) ($e['url'] ?? '');

            if ($ip !== '') {
                $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
            }

            if (str_contains($reason, '404') || str_contains($reason, 'Not Found') || str_contains($reason, 'error_burst')) {
                $ip404Counts[$ip] = ($ip404Counts[$ip] ?? 0) + 1;
            }

            if ($action === 'throttled') {
                $throttledCount++;
            }

            if (str_contains(strtolower($reason), 'fake search bot') || str_contains(strtolower($action), 'fake')) {
                $fakeBotCount++;
            }

            $aiBot = BotDetector::detectAiCrawler($ua);
            if ($aiBot !== null && $aiBot['type'] === 'training') {
                $aiTrainingCount++;
            }

            $parsedRoute = parse_url($url, PHP_URL_PATH) ?? $url;
            if ($parsedRoute !== '') {
                $routeCounts[$parsedRoute] = ($routeCounts[$parsedRoute] ?? 0) + 1;
            }
        }

        // Heuristic 1: Vulnerability / 404 Scanner Burst
        foreach ($ip404Counts as $suspiciousIp => $count) {
            if ($count >= 4 && !in_array($suspiciousIp, (array) ($currentSettings['blacklist'] ?? []), true)) {
                $recommendations[] = [
                    'id' => 'block_scanner_' . md5($suspiciousIp),
                    'severity' => 'danger',
                    'title' => "Обнаружен сканер уязвимостей (IP: {$suspiciousIp})",
                    'description' => "Клиент с адреса {$suspiciousIp} совершил {$count} запросов к несуществующим адресам или вызвал ошибки обхода. Рекомендуется заблокировать IP.",
                    'suggestedConfig' => [
                        'blacklist_add' => $suspiciousIp,
                    ],
                    'reason' => "Сканирование 404: {$count} ошибок за короткий промежуток.",
                ];
            }
        }

        // Heuristic 2: AI Training Scrapers
        if ($aiTrainingCount > 0 && empty($currentSettings['blockAiCrawlers'])) {
            $recommendations[] = [
                'id' => 'enable_block_ai_crawlers',
                'severity' => 'warning',
                'title' => 'Активировать блокировку обучающих AI-скраперов',
                'description' => "Зафиксировано {$aiTrainingCount} обращений от ботов обучения LLM (GPTBot, ClaudeBot, Bytespider). Включение защитит уникальный контент от выкачивания.",
                'suggestedConfig' => [
                    'blockAiCrawlers' => true,
                ],
                'reason' => "Зафиксировано {$aiTrainingCount} обращений скраперов обучения.",
            ];
        }

        // Heuristic 3: Fake Search Engine Bot Spoofing
        if ($fakeBotCount > 0 && empty($currentSettings['blockFakeSearchBots'])) {
            $recommendations[] = [
                'id' => 'enable_block_fake_search_bots',
                'severity' => 'danger',
                'title' => 'Включить жесткое отсечение поддельных поисковиков',
                'description' => 'Обнаружены боты, отправляющие фальшивый User-Agent Яндекса или Google. Активируйте проверку Reverse DNS для их мгновенной блокировки.',
                'suggestedConfig' => [
                    'verifySearchBots' => true,
                    'blockFakeSearchBots' => true,
                ],
                'reason' => "Обнаружено {$fakeBotCount} попыток спуфинга User-Agent.",
            ];
        }

        // Heuristic 4: Throttling Saturation -> Proof-of-Work
        $throttleRatio = count($events) > 0 ? ($throttledCount / count($events)) : 0;
        if ($throttleRatio > 0.10 && empty($currentSettings['powEnabled'])) {
            $recommendations[] = [
                'id' => 'enable_pow_challenge',
                'severity' => 'warning',
                'title' => 'Включить вычислительный Proof-of-Work челлендж',
                'description' => 'Более 10% входящих запросов превышают стандартные лимиты частоты. Локальный PoW заставит ботов расходовать CPU, отсекая автоматизированный спам без нагрузки на бэкенд.',
                'suggestedConfig' => [
                    'powEnabled' => true,
                    'powDifficulty' => 'medium',
                ],
                'reason' => sprintf('Высокая доля флуда: %.1f%% запросов ограничено.', $throttleRatio * 100),
            ];
        }

        // Heuristic 5: Search / Category Heavy Routes -> Weighted Rate Limiting
        $searchRequests = 0;
        foreach ($routeCounts as $r => $cnt) {
            if (str_contains($r, 'search') || str_contains($r, 'catalog') || str_contains($r, 'filter')) {
                $searchRequests += $cnt;
            }
        }
        if ($searchRequests >= 5 && empty($currentSettings['weightedRateLimitEnabled'])) {
            $recommendations[] = [
                'id' => 'enable_weighted_budget',
                'severity' => 'info',
                'title' => 'Включить дифференцированный бюджет нагрузки (Weighted Limiter)',
                'description' => "Зафиксирована концентрация запросов к тяжелым маршрутам поиска и каталога ({$searchRequests} запр.). Включение бюджета нагрузки защитит базу данных от исчерпания пула соединений.",
                'suggestedConfig' => [
                    'weightedRateLimitEnabled' => true,
                    'weightedRateLimitBudget' => 150,
                ],
                'reason' => "Тяжелые роуты составляют значительную часть трафика ({$searchRequests} запр.).",
            ];
        }

        return $recommendations;
    }
}
