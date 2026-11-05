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

            // Process route-specific sensitive rules
            $rawRouteRules = (array) ($post['routeRules'] ?? []);
            $cleanRouteRules = [];
            foreach ($rawRouteRules as $rule) {
                $pattern = trim((string) ($rule['pattern'] ?? ''));
                if ($pattern === '') {
                    continue;
                }
                $maxReq = max(1, (int) ($rule['maxRequests'] ?? 10));
                $win = max(1, (int) ($rule['window'] ?? 60));
                $methodsRaw = $rule['methods'] ?? [];
                $methods = is_array($methodsRaw) ? array_values(array_filter(array_map('strtoupper', array_map('trim', $methodsRaw)))) : [];
                $cleanRouteRules[] = [
                    'pattern' => $pattern,
                    'maxRequests' => $maxReq,
                    'window' => $win,
                    'methods' => $methods,
                    'description' => trim((string) ($rule['description'] ?? '')),
                ];
            }

            $payload = [
                'enabled' => !empty($post['enabled']),
                'mode' => (string) ($post['mode'] ?? 'standard'),
                'blockAiCrawlers' => !empty($post['blockAiCrawlers']),
                'blockCommercialScrapers' => !empty($post['blockCommercialScrapers']),
                'blockCliAndLibraries' => !empty($post['blockCliAndLibraries']),
                'headerConsistencyEnabled' => !empty($post['headerConsistencyEnabled']),
                'autoJailEnabled' => !empty($post['autoJailEnabled']),
                'autoJailThreshold' => max(1, (int) ($post['autoJailThreshold'] ?? 2)),
                'autoJailDuration' => max(60, (int) ($post['autoJailDuration'] ?? 1800)),
                'verifySearchBots' => !empty($post['verifySearchBots']),
                'blockFakeSearchBots' => !empty($post['blockFakeSearchBots']),
                'rateLimitEnabled' => !empty($post['rateLimitEnabled']),
                'rateLimitMax' => max(1, (int) ($post['rateLimitMax'] ?? 60)),
                'rateLimitWindow' => max(1, (int) ($post['rateLimitWindow'] ?? 60)),
                'rateLimitBanDuration' => max(0, (int) ($post['rateLimitBanDuration'] ?? 300)),
                'rateLimitAdapter' => in_array($post['rateLimitAdapter'] ?? '', ['cache', 'redis'], true) ? $post['rateLimitAdapter'] : 'cache',
                'routeRules' => $cleanRouteRules,
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
                'autoTrustLocalProxy' => !empty($post['autoTrustLocalProxy']),
                'language' => in_array($post['language'] ?? '', ['auto', 'ru', 'en'], true) ? $post['language'] : 'auto',
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
            $settings['headerConsistencyEnabled'] = $antibot->headerConsistencyEnabled;
            $settings['autoJailEnabled'] = $antibot->autoJailEnabled;
            $settings['autoJailThreshold'] = $antibot->autoJailThreshold;
            $settings['autoJailDuration'] = $antibot->autoJailDuration;
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
            $settings['autoTrustLocalProxy'] = $antibot->autoTrustLocalProxy;
            $settings['language'] = $antibot->language;
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
