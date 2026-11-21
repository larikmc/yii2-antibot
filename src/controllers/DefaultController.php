<?php

declare(strict_types=1);

namespace larikmc\antibot\controllers;

use larikmc\antibot\Module;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * Default controller for Antibot Dashboard.
 *
 * @property Module $module
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class DefaultController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'quick-block' => ['POST'],
                'quick-whitelist' => ['POST'],
                'clear-events' => ['POST'],
                'apply-recommendation' => ['POST'],
                'toggle-panic' => ['POST'],
                'release-jail' => ['POST'],
                'clear-jail' => ['POST'],
                'clear-dns-cache' => ['POST'],
            ],
        ];
        return $behaviors;
    }

    /**
     * Dashboard page showing traffic metrics, visual analytics charts, and live event feed.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        $scanLimit = max(500, (int) ($settings['eventHistoryScanLimit'] ?? 5000));
        $tableLimit = max(20, min(1000, (int) ($settings['liveFeedTableLimit'] ?? 100)));

        $stats = $storage->getStatistics($scanLimit);
        $events = $storage->getRecentEvents($tableLimit);
        $perf = $antibot->getInspectionMetrics();

        $currentLang = \larikmc\antibot\services\I18n::getLanguage();

        // Visual Analytics Dataset (cached 120s, no public overhead)
        $analyticsData = \larikmc\antibot\services\analytics\DashboardAnalyticsService::getAnalytics(
            $storage,
            \larikmc\antibot\services\analytics\DashboardAnalyticsService::PERIOD_24H,
            $antibot->mode,
            false,
            $currentLang,
            $scanLimit
        );

        $jailedIps = \larikmc\antibot\services\resilience\AutoJailService::getJailedIps();
        $panicModeActive = \larikmc\antibot\services\resilience\PanicModeService::isActive();
        $panicRemaining = \larikmc\antibot\services\resilience\PanicModeService::getRemainingSeconds();

        return $this->render('index', [
            'antibot' => $antibot,
            'module' => $this->module,
            'stats' => $stats,
            'events' => $events,
            'perf' => $perf,
            'analyticsData' => $analyticsData,
            'currentLang' => $currentLang,
            'jailedIps' => $jailedIps,
            'panicModeActive' => $panicModeActive,
            'panicRemaining' => $panicRemaining,
        ]);
    }

    /**
     * System diagnostics, heuristic rule advisor, rule simulator, and audit trail.
     *
     * @return string
     */
    public function actionDiagnostics(): string
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        $events = $storage->getRecentEvents(200);

        // Advisor Recommendations
        $recommendations = \larikmc\antibot\services\advisor\RuleAdvisor::generateRecommendations($events, $settings);

        // Simulator Evaluation
        $simType = $this->request->get('sim_type', 'ratelimit');
        $simMax = (int) $this->request->get('sim_max', 20);
        $simWindow = (int) $this->request->get('sim_window', 60);
        $simUa = trim((string) $this->request->get('sim_ua', 'Python'));

        $simResult = match ($simType) {
            'ua' => \larikmc\antibot\services\advisor\RuleSimulator::simulateUserAgentBlock($events, $simUa),
            default => \larikmc\antibot\services\advisor\RuleSimulator::simulateRateLimit($events, $simMax, $simWindow),
        };

        if ($this->request->isAjax) {
            $response = Yii::$app->response;
            $response->format = Response::FORMAT_JSON;
            $response->data = [
                'success' => true,
                'result' => $simResult,
                'simType' => $simType,
                'simMax' => $simMax,
                'simWindow' => $simWindow,
                'simUa' => $simUa,
                'timestamp' => date('H:i:s'),
            ];
            return $response;
        }

        // Audit Trail and Metrics
        $auditTrail = $storage->getAuditTrail(15);
        $perf = $antibot->getInspectionMetrics();
        $selfTest = \larikmc\antibot\services\resilience\SelfTest::run($antibot);

        return $this->render('diagnostics', [
            'antibot' => $antibot,
            'module' => $this->module,
            'recommendations' => $recommendations,
            'simResult' => $simResult,
            'simType' => $simType,
            'simMax' => $simMax,
            'simWindow' => $simWindow,
            'simUa' => $simUa,
            'auditTrail' => $auditTrail,
            'perf' => $perf,
            'selfTest' => $selfTest,
            'currentLang' => \larikmc\antibot\services\I18n::getLanguage(),
        ]);
    }

    /**
     * AJAX endpoint for Rule Simulator "What-If" analysis.
     *
     * @return Response
     */
    public function actionSimulate(): Response
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $events = $storage->getRecentEvents(500);

        $simType = $this->request->get('sim_type', 'ratelimit');
        $simMax = (int) $this->request->get('sim_max', 20);
        $simWindow = (int) $this->request->get('sim_window', 60);
        $simUa = trim((string) $this->request->get('sim_ua', 'Python'));
        $lang = \larikmc\antibot\services\I18n::getLanguage();

        $simResult = match ($simType) {
            'ua' => \larikmc\antibot\services\advisor\RuleSimulator::simulateUserAgentBlock($events, $simUa, $lang),
            default => \larikmc\antibot\services\advisor\RuleSimulator::simulateRateLimit($events, $simMax, $simWindow, $lang),
        };

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->data = [
            'success' => true,
            'result' => $simResult,
            'simType' => $simType,
            'simMax' => $simMax,
            'simWindow' => $simWindow,
            'simUa' => $simUa,
            'timestamp' => date('H:i:s'),
        ];
        return $response;
    }

    /**
     * Returns JSON analytics dataset for dashboard charts (ECharts).
     * Protected by module's AccessControl behavior.
     *
     * @return Response
     */
    public function actionAnalyticsData(): Response
    {
        $period = (string) $this->request->get('period', '24h');
        $forceRefresh = (bool) $this->request->get('refresh', false);
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        $scanLimit = max(500, (int) ($settings['eventHistoryScanLimit'] ?? 5000));
        $currentLang = \larikmc\antibot\services\I18n::getLanguage();

        $data = \larikmc\antibot\services\analytics\DashboardAnalyticsService::getAnalytics(
            $storage,
            $period,
            $antibot->mode,
            $forceRefresh,
            $currentLang,
            $scanLimit
        );

        return $this->asJson($data);
    }

    /**
     * Streams and exports event history in CSV or JSONL format for offline analysis.
     * Protected by module's AccessControl behavior (administrators only).
     *
     * @return Response
     */
    public function actionExportEvents(): Response
    {
        $format = strtolower(trim((string) $this->request->get('format', 'csv')));
        $filter = strtolower(trim((string) $this->request->get('filter', 'all')));
        $includeRotated = (bool) $this->request->get('include_rotated', true);

        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();

        $timestamp = date('Ymd_His');
        $filterSuffix = $filter === 'blocked' ? '_blocked' : '';

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;

        if ($format === 'jsonl') {
            $filename = "antibot_events{$filterSuffix}_{$timestamp}.jsonl";
            $response->headers->set('Content-Type', 'application/x-ndjson; charset=UTF-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

            $response->stream = function () use ($storage, $filter, $includeRotated): void {
                $storage->streamEvents(function (array $event, string $rawLine) use ($filter): bool {
                    if ($filter === 'blocked') {
                        $action = (string) ($event['action'] ?? '');
                        if ($action !== 'blocked' && $action !== 'throttled') {
                            return true;
                        }
                    }
                    echo $rawLine . "\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    return true;
                }, $includeRotated);
            };

            return $response;
        }

        // Default: CSV format
        $filename = "antibot_events{$filterSuffix}_{$timestamp}.csv";
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        $response->stream = function () use ($storage, $filter, $includeRotated): void {
            $out = fopen('php://output', 'wb');
            if (!$out) {
                return;
            }

            // UTF-8 BOM for Microsoft Excel compatibility
            fwrite($out, "\xEF\xBB\xBF");

            // CSV Header
            fputcsv($out, [
                'Timestamp',
                'DateTime',
                'IP Address',
                'Action',
                'Reason',
                'Bot Key',
                'Bot Category',
                'Verification',
                'URL',
                'User-Agent',
            ]);

            $storage->streamEvents(function (array $event) use ($out, $filter): bool {
                $action = (string) ($event['action'] ?? '');
                if ($filter === 'blocked' && $action !== 'blocked' && $action !== 'throttled') {
                    return true;
                }

                $ts = (int) ($event['timestamp'] ?? time());
                $formattedDate = \larikmc\antibot\services\I18n::formatTimestamp($ts, $event['date'] ?? null);

                $sanitize = static function (string $val): string {
                    if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                        return "'" . $val;
                    }
                    return $val;
                };

                fputcsv($out, [
                    $ts,
                    $formattedDate,
                    $sanitize((string) ($event['ip'] ?? '')),
                    $sanitize($action),
                    $sanitize((string) ($event['reason'] ?? '')),
                    $sanitize((string) ($event['bot_key'] ?? '')),
                    $sanitize((string) ($event['bot_category'] ?? '')),
                    $sanitize((string) ($event['verification_status'] ?? '')),
                    $sanitize((string) ($event['url'] ?? ($event['route'] ?? ''))),
                    $sanitize((string) ($event['userAgent'] ?? '')),
                ]);

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                return true;
            }, $includeRotated);

            fclose($out);
        };

        return $response;
    }

    /**
     * Applies a recommended security adjustment suggested by RuleAdvisor.
     */
    public function actionApplyRecommendation(): Response
    {
        $recId = trim((string) $this->request->post('rec_id', ''));
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        if (str_starts_with($recId, 'block_scanner_')) {
            $ip = trim((string) $this->request->post('ip', ''));
            if ($ip !== '') {
                // Safety guard: Check candidate IP for intersection with protected search & ad systems
                $intersection = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection($ip);
                if ($intersection['intersects']) {
                    $lang = \larikmc\antibot\services\I18n::getLanguage();
                    $sysName = $intersection['system'] ?? 'Protected Bot';
                    $matchedRange = $intersection['range'] ?? '';
                    $msg = $lang === 'en'
                        ? "Cannot block '{$ip}': IP intersects with protected {$sysName} ({$matchedRange})!"
                        : "Невозможно заблокировать '{$ip}': IP пересекается с защищенной инфраструктурой {$sysName} ({$matchedRange})!";
                    Yii::$app->session->setFlash('warning', $msg);
                    return $this->redirect(['diagnostics']);
                }

                $blacklist = (array) ($settings['blacklist'] ?? []);
                if (!in_array($ip, $blacklist, true)) {
                    $blacklist[] = $ip;
                    $settings['blacklist'] = array_values(array_unique($blacklist));
                    $storage->saveSettings($settings, $antibot->resolveClientIp());
                    Yii::$app->session->setFlash('success', "IP {$ip} успешно заблокирован по рекомендации советника.");
                }
            }
        } elseif ($recId === 'enable_block_ai_crawlers') {
            $settings['blockAiCrawlers'] = true;
            $storage->saveSettings($settings, $antibot->resolveClientIp());
            Yii::$app->session->setFlash('success', 'Блокировка AI-скраперов успешно активирована.');
        } elseif ($recId === 'enable_block_fake_search_bots') {
            $settings['verifySearchBots'] = true;
            $settings['blockFakeSearchBots'] = true;
            $storage->saveSettings($settings, $antibot->resolveClientIp());
            Yii::$app->session->setFlash('success', 'Верификация Reverse DNS и блокировка фейковых поисковиков включены.');
        } elseif ($recId === 'enable_pow_challenge') {
            $settings['powEnabled'] = true;
            $settings['powDifficulty'] = 'medium';
            $storage->saveSettings($settings, $antibot->resolveClientIp());
            Yii::$app->session->setFlash('success', 'Proof-of-Work челлендж успешно активирован.');
        } elseif ($recId === 'enable_weighted_budget') {
            $settings['weightedRateLimitEnabled'] = true;
            $settings['weightedRateLimitBudget'] = 150;
            $storage->saveSettings($settings, $antibot->resolveClientIp());
            Yii::$app->session->setFlash('success', 'Дифференцированный бюджет нагрузки (Weighted Limiter) включен.');
        }

        return $this->redirect(['diagnostics']);
    }

    /**
     * Quick action to add an IP directly to the blacklist.
     *
     * @return Response
     */
    public function actionQuickBlock(): Response
    {
        $ip = trim((string) $this->request->post('ip', ''));
        $subnet24 = (bool) $this->request->post('subnet24', false);

        if ($ip !== '') {
            $antibot = $this->module->getAntibot();
            $storage = $antibot->getStorage();
            $settings = $storage->loadSettings();

            $target = $ip;
            if ($subnet24) {
                $target = \larikmc\antibot\services\IpMatcher::getSubnet24($ip) ?? $ip;
            }

            // Safety guard: Check entire candidate range (/24 subnet or single IP) for intersection with protected search & ad systems
            $intersection = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection($target);
            if ($intersection['intersects']) {
                $lang = \larikmc\antibot\services\I18n::getLanguage();
                $sysName = $intersection['system'] ?? 'Protected Bot';
                $matchedRange = $intersection['range'] ?? '';
                $msg = $lang === 'en'
                    ? "Cannot block '{$target}': range intersects with protected {$sysName} ({$matchedRange})!"
                    : "Невозможно заблокировать '{$target}': диапазон пересекается с защищенной инфраструктурой {$sysName} ({$matchedRange})!";
                Yii::$app->session->setFlash('warning', $msg);
                return $this->redirect(Yii::$app->request->referrer ?: ['index']);
            }

            $blacklist = (array) ($settings['blacklist'] ?? []);
            if (!in_array($target, $blacklist, true)) {
                $blacklist[] = $target;
                $settings['blacklist'] = array_values(array_unique($blacklist));
                $storage->saveSettings($settings);
                Yii::$app->session->setFlash('success', "Запись '{$target}' успешно добавлена в черный список.");
            } else {
                Yii::$app->session->setFlash('info', "Запись '{$target}' уже находится в черном списке.");
            }
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Quick action to add an IP directly to the whitelist.
     *
     * @return Response
     */
    public function actionQuickWhitelist(): Response
    {
        $ip = trim((string) $this->request->post('ip', ''));
        if ($ip !== '') {
            $antibot = $this->module->getAntibot();
            $storage = $antibot->getStorage();
            $settings = $storage->loadSettings();

            $whitelist = (array) ($settings['whitelist'] ?? []);
            if (!in_array($ip, $whitelist, true)) {
                $whitelist[] = $ip;
                $settings['whitelist'] = array_values(array_unique($whitelist));
                $storage->saveSettings($settings);
                Yii::$app->session->setFlash('success', "IP {$ip} успешно добавлен в белый список.");
            }
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Clears all recorded security events.
     *
     * @return Response
     */
    public function actionClearEvents(): Response
    {
        $antibot = $this->module->getAntibot();
        $antibot->getStorage()->clearEvents();
        Yii::$app->session->setFlash('success', 'Журнал событий успешно очищен.');
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Toggles elevated Panic / Under-Attack defense mode.
     *
     * @return Response
     */
    public function actionTogglePanic(): Response
    {
        $isActive = \larikmc\antibot\services\resilience\PanicModeService::isActive();

        if ($isActive) {
            \larikmc\antibot\services\resilience\PanicModeService::deactivate();
            Yii::$app->session->setFlash('info', 'Режим повышенной защиты (Under Attack) отключен.');
        } else {
            $duration = max(60, (int) $this->request->post('duration', 900));
            \larikmc\antibot\services\resilience\PanicModeService::activate($duration);
            $mins = (int) ceil($duration / 60);
            Yii::$app->session->setFlash('warning', "🛡️ Режим повышенной защиты АКТИВИРОВАН на {$mins} мин. Порог запросов снижен, включена строгая проверка!");
        }

        if ($this->request->isAjax) {
            $response = Yii::$app->response;
            $response->format = Response::FORMAT_JSON;
            $response->data = [
                'success' => true,
                'isActive' => \larikmc\antibot\services\resilience\PanicModeService::isActive(),
                'remainingSec' => \larikmc\antibot\services\resilience\PanicModeService::getRemainingSeconds(),
            ];
            return $response;
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Releases an IP from Auto-Jail quarantine.
     *
     * @return Response
     */
    public function actionReleaseJail(): Response
    {
        $ip = trim((string) $this->request->post('ip', ''));
        if ($ip !== '') {
            \larikmc\antibot\services\resilience\AutoJailService::releaseIp($ip);
            Yii::$app->session->setFlash('success', "IP {$ip} успешно освобожден из карантина Auto-Jail.");
        }

        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Clears all quarantined IPs from Auto-Jail.
     *
     * @return Response
     */
    public function actionClearJail(): Response
    {
        \larikmc\antibot\services\resilience\AutoJailService::clearAll();
        Yii::$app->session->setFlash('success', 'Все адреса успешно освобождены из карантина Auto-Jail.');
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    /**
     * Clears DNS verification caches (positive, negative, temporary, or specific IP).
     *
     * @return Response
     */
    public function actionClearDnsCache(): Response
    {
        $type = (string) $this->request->post('type', 'all');
        $ip = trim((string) $this->request->post('ip', ''));
        $engine = trim((string) $this->request->post('engine', ''));

        \larikmc\antibot\services\ReverseDnsVerifier::clearCache($type, $ip, $engine);

        $msg = match ($type) {
            'verified' => 'Положительный кэш подтверждённых роботов (VERIFIED) успешно очищен.',
            'fake' => 'Негативный кэш доказанных подделок (FAKE) успешно очищен.',
            'single' => "Запись кэша верификации для {$ip} ({$engine}) успешно удалена.",
            default => 'Кэш DNS-верификации роботов полностью очищен.',
        };

        Yii::$app->session->setFlash('success', $msg);
        return $this->redirect(Yii::$app->request->referrer ?: ['settings/index']);
    }
}
