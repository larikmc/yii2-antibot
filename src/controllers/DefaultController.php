<?php

declare(strict_types=1);

namespace larikmc\antibot\controllers;

use larikmc\antibot\Module;
use Yii;
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
     * Dashboard page showing traffic metrics and real-time blocked bot logs.
     *
     * @return Response|string
     */
    public function actionIndex()
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();

        $stats = $storage->getStatistics();
        $events = $storage->getRecentEvents(50);

        return $this->render('index', [
            'antibot' => $antibot,
            'stats' => $stats,
            'events' => $events,
        ]);
    }

    /**
     * Quick action to add an IP directly to the blacklist.
     *
     * @param string $ip
     * @return Response
     */
    public function actionQuickBlock(string $ip): Response
    {
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

        return $this->redirect(['index']);
    }

    /**
     * Quick action to add an IP directly to the whitelist.
     *
     * @param string $ip
     * @return Response
     */
    public function actionQuickWhitelist(string $ip): Response
    {
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

        return $this->redirect(['index']);
    }
}
