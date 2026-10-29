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
 * Supports RBAC permissions, role-based access, and custom authorization callbacks.
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
     * @var array Allowed roles for AccessControl.
     * Defaults to ['admin'] for security (['@'] allows any registered user and is not recommended).
     */
    public array $accessRoles = ['admin'];

    /**
     * @var string|null Optional RBAC permission name (e.g. 'antibot.manage').
     * If configured and RBAC is present, takes precedence.
     */
    public ?string $accessPermission = 'antibot.manage';

    /**
     * @var callable|null Custom authorization callback: function (\yii\web\User $user): bool
     */
    public $accessCallback = null;

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

        $behaviors['access'] = [
            'class' => AccessControl::class,
            'rules' => [
                [
                    'allow' => true,
                    'matchCallback' => function ($rule, $action): bool {
                        if (Yii::$app === null || Yii::$app->user === null || Yii::$app->user->isGuest) {
                            return false;
                        }

                        $user = Yii::$app->user;

                        // 1. Custom callback
                        if (is_callable($this->accessCallback)) {
                            return (bool) call_user_func($this->accessCallback, $user);
                        }

                        // 2. RBAC permission check
                        if (!empty($this->accessPermission) && $user->can($this->accessPermission)) {
                            return true;
                        }

                        // 3. Fallback to accessRoles
                        if (empty($this->accessRoles)) {
                            return false;
                        }

                        // If '@' is explicitly configured for backward compatibility
                        if (in_array('@', $this->accessRoles, true)) {
                            return true;
                        }

                        // Check roles via RBAC can() or user identity check
                        foreach ($this->accessRoles as $role) {
                            if ($user->can($role)) {
                                return true;
                            }
                        }

                        return false;
                    },
                ],
            ],
        ];

        return $behaviors;
    }

    /**
     * Checks if the module is configured with potentially insecure '@' role without RBAC.
     */
    public function hasInsecureAccessConfig(): bool
    {
        return in_array('@', $this->accessRoles, true) && empty($this->accessPermission);
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

        // Fallback: create standalone component instance
        return new Antibot();
    }
}
