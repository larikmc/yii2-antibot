<?php

declare(strict_types=1);

namespace larikmc\antibot\controllers;

use larikmc\antibot\Module;
use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * IP list controller for Whitelist and Blacklist management.
 *
 * @property Module $module
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class IpListController extends Controller
{
    /**
     * Shows both Whitelist and Blacklist tables with add/delete forms.
     *
     * @return Response|string
     */
    public function actionIndex()
    {
        $antibot = $this->module->getAntibot();
        $storage = $antibot->getStorage();
        $settings = $storage->loadSettings();

        if ($this->request->isPost) {
            $type = (string) $this->request->post('type', '');
            $entry = trim((string) $this->request->post('ip_entry', ''));

            if ($entry !== '' && in_array($type, ['whitelist', 'blacklist'], true)) {
                $list = (array) ($settings[$type] ?? []);
                if (!in_array($entry, $list, true)) {
                    $list[] = $entry;
                    $settings[$type] = array_values(array_unique($list));
                    $storage->saveSettings($settings);
                    Yii::$app->session->setFlash('success', "Запись '{$entry}' успешно добавлена в {$type}.");
                }
            }

            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'whitelist' => (array) ($settings['whitelist'] ?? []),
            'blacklist' => (array) ($settings['blacklist'] ?? []),
        ]);
    }

    /**
     * Removes an entry from whitelist or blacklist.
     *
     * @param string $type 'whitelist' or 'blacklist'
     * @param string $entry
     * @return Response
     */
    public function actionDelete(string $type, string $entry): Response
    {
        if (in_array($type, ['whitelist', 'blacklist'], true)) {
            $antibot = $this->module->getAntibot();
            $storage = $antibot->getStorage();
            $settings = $storage->loadSettings();

            $list = (array) ($settings[$type] ?? []);
            $key = array_search($entry, $list, true);
            if ($key !== false) {
                unset($list[$key]);
                $settings[$type] = array_values($list);
                $storage->saveSettings($settings);
                Yii::$app->session->setFlash('success', "Запись '{$entry}' удалена из {$type}.");
            }
        }

        return $this->redirect(['index']);
    }
}
