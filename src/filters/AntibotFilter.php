<?php

declare(strict_types=1);

namespace larikmc\antibot\filters;

use larikmc\antibot\Antibot;
use Yii;
use yii\base\ActionFilter;

/**
 * Optional Action Filter for controller-level AntiBot protection and rate limiting.
 *
 * Usage in controller:
 * ```php
 * public function behaviors(): array
 * {
 *     return [
 *         'antibot' => [
 *             'class' => \larikmc\antibot\filters\AntibotFilter::class,
 *             'maxRequests' => 10,
 *             'window' => 60,
 *             'only' => ['login', 'signup', 'search'],
 *         ],
 *     ];
 * }
 * ```
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class AntibotFilter extends ActionFilter
{
    /**
     * @var int Max requests for actions covered by this filter.
     */
    public int $maxRequests = 30;

    /**
     * @var int Window duration in seconds.
     */
    public int $window = 60;

    /**
     * @var int Temporary ban duration in seconds if exceeded.
     */
    public int $banDuration = 300;

    /**
     * @var string ID of the Antibot component.
     */
    public string $componentId = 'antibot';

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action): bool
    {
        if (Yii::$app === null || !Yii::$app->has($this->componentId)) {
            return true;
        }

        $antibot = Yii::$app->get($this->componentId);
        if (!$antibot instanceof Antibot || !$antibot->enabled) {
            return true;
        }

        $ip = $antibot->resolveClientIp();
        $route = $action->getUniqueId();
        $key = sprintf('%s_%s', $ip, $route);

        $limitResult = \larikmc\antibot\services\RateLimiter::check($key, $this->maxRequests, $this->window, $this->banDuration);
        if ($limitResult['exceeded']) {
            $antibot->throttleRequest(
                sprintf('Action rate limit exceeded on "%s" (%d req/%ds)', $route, $this->maxRequests, $this->window),
                $ip,
                $limitResult['retryAfter']
            );
            return false;
        }

        return true;
    }
}
