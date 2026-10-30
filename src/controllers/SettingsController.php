<?php

declare(strict_types=1);

namespace larikmc\antibot\controllers;

use larikmc\antibot\Module;
use larikmc\antibot\services\IpMatcher;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * Settings controller for Antibot security rules.
 *
 * @property Module $module
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class SettingsController extends Controller
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
                'index' => ['GET', 'POST'],
            ],
        ];
        return $behaviors;
    }

    /**
     * Settings configuration form.
     *
     * @return Response|string
     */
    public function actionIndex()
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();

        if ($this->request->isPost) {
            $post = $this->request->post('AntibotSetting', []);

            // Process trusted proxies
            $trustedProxiesRaw = (string) ($post['trustedProxies'] ?? '');
            $trustedProxies = [];
            foreach (preg_split('/[\r\n,]+/', $trustedProxiesRaw) as $item) {
                $item = trim($item);
                if ($item !== '' && IpMatcher::isValidIpOrCidr($item)) {
                    $trustedProxies[] = $item;
                }
            }

            // Process AI crawler policies
            $aiPolicies = (array) ($post['aiCrawlerPolicies'] ?? []);
            $cleanAiPolicies = [];
            foreach ($aiPolicies as $bot => $action) {
                if (in_array($action, ['allow', 'monitor', 'throttle', 'block'], true)) {
                    $cleanAiPolicies[$bot] = $action;
                }
            }

            $payload = [
                'enabled' => !empty($post['enabled']),
                'mode' => (string) ($post['mode'] ?? 'standard'),
                'blockAiCrawlers' => !empty($post['blockAiCrawlers']),
                'blockCommercialScrapers' => !empty($post['blockCommercialScrapers']),
                'blockCliAndLibraries' => !empty($post['blockCliAndLibraries']),
                'verifySearchBots' => !empty($post['verifySearchBots']),
                'blockFakeSearchBots' => !empty($post['blockFakeSearchBots']),
                'rateLimitEnabled' => !empty($post['rateLimitEnabled']),
                'rateLimitMax' => max(1, (int) ($post['rateLimitMax'] ?? 60)),
                'rateLimitWindow' => max(1, (int) ($post['rateLimitWindow'] ?? 60)),
                'rateLimitBanDuration' => max(0, (int) ($post['rateLimitBanDuration'] ?? 300)),
                'rateLimitAdapter' => in_array($post['rateLimitAdapter'] ?? '', ['cache', 'redis'], true) ? $post['rateLimitAdapter'] : 'cache',
                'behaviorAnalysisEnabled' => !empty($post['behaviorAnalysisEnabled']),
                'weightedRateLimitEnabled' => !empty($post['weightedRateLimitEnabled']),
                'weightedRateLimitBudget' => max(10, (int) ($post['weightedRateLimitBudget'] ?? 150)),
                'weightedRateLimitWindow' => max(5, (int) ($post['weightedRateLimitWindow'] ?? 60)),
                'powEnabled' => !empty($post['powEnabled']),
                'powDifficulty' => in_array($post['powDifficulty'] ?? '', ['light', 'medium', 'strong'], true) ? $post['powDifficulty'] : 'medium',
                'honeyTrapsEnabled' => !empty($post['honeyTrapsEnabled']),
                'trapRoute' => trim((string) ($post['trapRoute'] ?? 'antibot/trap')),
                'jsChallengeEnabled' => !empty($post['jsChallengeEnabled']),
                'trustedProxies' => array_values(array_unique($trustedProxies)),
                'aiCrawlerPolicies' => $cleanAiPolicies,
                'customBlockMessage' => trim((string) ($post['customBlockMessage'] ?? 'Access Denied: Automated bot activity detected.')),
            ];

            try {
                $storage->saveSettings($payload);
                Yii::$app->session->setFlash('success', 'Настройки антибота успешно сохранены!');
            } catch (\Throwable $e) {
                Yii::$app->session->setFlash('error', 'Ошибка при сохранении: ' . $e->getMessage());
            }

            return $this->redirect(['index']);
        }

        $settings = $storage->loadSettings();
        if (!$storage->hasSavedSettings()) {
            // Priority 2: When no file was explicitly saved yet, display active Yii2 application configuration
            $settings['enabled'] = $antibot->enabled;
            $settings['mode'] = $antibot->mode;
            $settings['blockAiCrawlers'] = $antibot->blockAiCrawlers;
            $settings['blockCommercialScrapers'] = $antibot->blockCommercialScrapers;
            $settings['blockCliAndLibraries'] = $antibot->blockCliAndLibraries;
            $settings['verifySearchBots'] = $antibot->verifySearchBots;
            $settings['blockFakeSearchBots'] = $antibot->blockFakeSearchBots;
            $settings['rateLimitEnabled'] = $antibot->rateLimitEnabled;
            $settings['rateLimitMax'] = $antibot->rateLimitMax;
            $settings['rateLimitWindow'] = $antibot->rateLimitWindow;
            $settings['rateLimitBanDuration'] = $antibot->rateLimitBanDuration;
            $settings['rateLimitAdapter'] = $antibot->rateLimitAdapter;
            $settings['jsChallengeEnabled'] = $antibot->jsChallengeEnabled;
            $settings['whitelist'] = $antibot->whitelist;
            $settings['blacklist'] = $antibot->blacklist;
            $settings['trustedProxies'] = $antibot->trustedProxies;
            $settings['ipHeaders'] = $antibot->ipHeaders;
            $settings['aiCrawlerPolicies'] = $antibot->aiCrawlerPolicies;
            $settings['routeRules'] = $antibot->routeRules;
            $settings['behaviorAnalysisEnabled'] = $antibot->behaviorAnalysisEnabled;
            $settings['weightedRateLimitEnabled'] = $antibot->weightedRateLimitEnabled;
            $settings['weightedRateLimitBudget'] = $antibot->weightedRateLimitBudget;
            $settings['weightedRateLimitWindow'] = $antibot->weightedRateLimitWindow;
            $settings['routeWeights'] = $antibot->routeWeights;
            $settings['powEnabled'] = $antibot->powEnabled;
            $settings['powDifficulty'] = $antibot->powDifficulty;
            $settings['honeyTrapsEnabled'] = $antibot->honeyTrapsEnabled;
            $settings['trapRoute'] = $antibot->trapRoute;
            $settings['customBlockMessage'] = $antibot->customBlockMessage;
        }

        return $this->render('index', [
            'settings' => $settings,
            'antibot' => $antibot,
        ]);
    }
}
