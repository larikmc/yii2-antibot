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
            ],
        ];
        return $behaviors;
    }

    /**
     * Dashboard page showing traffic metrics, rule recommendations, and real-time logs.
     *
     * @return Response|string
     */
    public function actionIndex()
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        $stats = $storage->getStatistics(1000);
        $events = $storage->getRecentEvents(100);

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

        // Audit Trail and Metrics
        $auditTrail = $storage->getAuditTrail(8);
        $perf = $antibot->getInspectionMetrics();
        $selfTest = \larikmc\antibot\services\resilience\SelfTest::run($antibot);

        return $this->render('index', [
            'antibot' => $antibot,
            'module' => $this->module,
            'stats' => $stats,
            'events' => $events,
            'recommendations' => $recommendations,
            'simResult' => $simResult,
            'simType' => $simType,
            'simMax' => $simMax,
            'simWindow' => $simWindow,
            'simUa' => $simUa,
            'auditTrail' => $auditTrail,
            'perf' => $perf,
            'selfTest' => $selfTest,
        ]);
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

        return $this->redirect(['index']);
    }

    /**
     * Quick action to add an IP directly to the blacklist.
     *
     * @return Response
     */
    public function actionQuickBlock(): Response
    {
        $ip = trim((string) $this->request->post('ip', ''));
        if ($ip !== '') {
            $antibot = $this->module->getAntibot();
            $storage = $antibot->getStorage();
            $settings = $storage->loadSettings();

            $blacklist = (array) ($settings['blacklist'] ?? []);
            if (!in_array($ip, $blacklist, true)) {
                $blacklist[] = $ip;
                $settings['blacklist'] = array_values(array_unique($blacklist));
                $storage->saveSettings($settings);
                Yii::$app->session->setFlash('success', "IP {$ip} успешно добавлен в черный список.");
            }
        }

        return $this->redirect(['index']);
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

        return $this->redirect(['index']);
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
        return $this->redirect(['index']);
    }
}
