<?php

declare(strict_types=1);

namespace larikmc\antibot\assets;

use yii\web\AssetBundle;

/**
 * Asset bundle for Antibot Admin Dashboard and visual analytics.
 *
 * Encapsulates Apache ECharts and dashboard UI styles/scripts.
 * Only registered in the Antibot admin dashboard views.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class AntibotDashboardAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/web';

    public $css = [
        'css/dashboard.css',
    ];

    public $js = [
        'js/echarts.common.min.js',
        'js/dashboard-charts.js',
    ];

    public $depends = [
        \yii\web\YiiAsset::class,
    ];
}
