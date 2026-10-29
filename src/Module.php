<?php

declare(strict_types=1);

namespace larikmc\antibot;

use Yii;
use yii\base\Module as BaseModule;
use yii\filters\AccessControl;

/**
 * Universal Anti-Bot Management Module for Yii2.
 *
 * Provides a dedicated admin dashboard to monitor bot traffic,
 * manage IP whitelists/blacklists, and configure security rules.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class Module extends BaseModule
{
    /**
     * @var string Controller namespace for this module.
     */
    public $controllerNamespace = 'larikmc\antibot\controllers';

    /**
     * @var string Default route.
     */
    public $defaultRoute = 'default/index';

    /**
     * @var string ID of the Antibot application component in Yii::$app.
     */
    public string $componentId = 'antibot';

    /**
     * @var array Allowed roles for AccessControl. Defaults to ['@'] (authenticated users).
     * Set to empty array [] to disable RBAC check.
     */
    public array $accessRoles = ['@'];

    /**
     * @var string|null Custom layout name, or null to inherit the application's layout.
     */
    public $layout = null;

    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        if (!empty($this->accessRoles)) {
            $behaviors['access'] = [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => $this->accessRoles,
                    ],
                ],
            ];
        }

        return $behaviors;
    }

    /**
     * Resolves the active Antibot component instance.
     */
    public function getAntibot(): Antibot
    {
        if (Yii::$app !== null && Yii::$app->has($this->componentId)) {
            $comp = Yii::$app->get($this->componentId);
            if ($comp instanceof Antibot) {
                return $comp;
            }
        }

        return new Antibot();
    }
}
