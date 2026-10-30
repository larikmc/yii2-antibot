<?php

declare(strict_types=1);

namespace larikmc\antibot;

use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Extension bootstrap class.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap($app): void
    {
        // Only bootstrap for web applications; console commands should not run web inspection.
        if ($app instanceof \yii\web\Application && $app->has('antibot')) {
            $antibot = $app->get('antibot');
            if ($antibot instanceof Antibot) {
                $antibot->bootstrap($app);
            }
        }
    }
}
