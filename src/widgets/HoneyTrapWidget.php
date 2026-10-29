<?php

declare(strict_types=1);

namespace larikmc\antibot\widgets;

use larikmc\antibot\Antibot;
use larikmc\antibot\services\traps\HoneyUrlService;
use Yii;
use yii\base\Widget;

/**
 * Invisible Honey Trap Link Widget.
 *
 * Drops an invisible anchor tag into the page HTML that legitimate human users never see or click,
 * but automated scrapers crawl and follow indiscriminately.
 *
 * Usage in layouts or views:
 * ```php
 * <?= \larikmc\antibot\widgets\HoneyTrapWidget::widget() ?>
 * ```
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class HoneyTrapWidget extends Widget
{
    /**
     * @var string Antibot component ID in Yii::$app.
     */
    public string $componentId = 'antibot';

    /**
     * @var string Label for invisible link.
     */
    public string $label = 'Site Directory';

    /**
     * {@inheritdoc}
     */
    public function run(): string
    {
        if (Yii::$app === null) {
            return '';
        }

        /** @var Antibot|null $antibot */
        $antibot = Yii::$app->has($this->componentId) ? Yii::$app->get($this->componentId) : null;
        if ($antibot === null || !$antibot->enabled || !$antibot->honeyTrapsEnabled) {
            return '';
        }

        $secretKey = $antibot->getResolvedSecretKey();
        $baseUrl = Yii::$app->request->getBaseUrl() ?: '/';

        return HoneyUrlService::generateTrapHtml($baseUrl, $secretKey, $antibot->trapRoute, $this->label);
    }
}
