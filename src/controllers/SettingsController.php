<?php

declare(strict_types=1);

namespace larikmc\antibot\controllers;

use larikmc\antibot\Module;
use Yii;
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
                'jsChallengeEnabled' => !empty($post['jsChallengeEnabled']),
                'customBlockMessage' => trim((string) ($post['customBlockMessage'] ?? '')),
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
