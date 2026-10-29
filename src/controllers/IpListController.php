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
 * IP list controller for Whitelist, Blacklist, and Trusted Proxies management.
 *
 * @property Module $module
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class IpListController extends Controller
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
                'delete' => ['POST'],
            ],
        ];
        return $behaviors;
    }

    /**
     * Shows Whitelist and Blacklist tables with add/delete forms.
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

            if (!in_array($type, ['whitelist', 'blacklist', 'trustedProxies'], true)) {
                Yii::$app->session->setFlash('error', 'Недопустимый тип списка.');
                return $this->redirect(['index']);
            }

            if (!IpMatcher::isValidIpOrCidr($entry)) {
                Yii::$app->session->setFlash('error', "Некорректный IP или CIDR: '{$entry}'. Пример: 95.26.144.17 или 10.0.0.0/8.");
                return $this->redirect(['index']);
            }

            $list = (array) ($settings[$type] ?? []);
            if (!in_array($entry, $list, true)) {
                $list[] = $entry;
                $settings[$type] = array_values(array_unique($list));
                $storage->saveSettings($settings);
                Yii::$app->session->setFlash('success', "Запись '{$entry}' успешно добавлена в {$type}.");
            } else {
                Yii::$app->session->setFlash('info', "Запись '{$entry}' уже присутствует в списке.");
            }

            return $this->redirect(['index']);
        }

        return $this->render('index', [
            'whitelist' => (array) ($settings['whitelist'] ?? []),
            'blacklist' => (array) ($settings['blacklist'] ?? []),
            'trustedProxies' => (array) ($settings['trustedProxies'] ?? []),
        ]);
    }

    /**
     * Removes an entry from whitelist, blacklist, or trustedProxies via POST.
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $type = (string) $this->request->post('type', '');
        $entry = trim((string) $this->request->post('entry', ''));

        if (in_array($type, ['whitelist', 'blacklist', 'trustedProxies'], true) && $entry !== '') {
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
