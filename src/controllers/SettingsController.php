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

        return $this->render('index', [
            'settings' => $settings,
            'antibot' => $antibot,
        ]);
    }
}
