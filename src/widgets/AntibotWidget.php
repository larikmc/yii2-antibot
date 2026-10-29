<?php

declare(strict_types=1);

namespace larikmc\antibot\widgets;

use larikmc\antibot\Antibot;
use larikmc\antibot\services\TokenService;
use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Client-side Anti-Bot verification and fingerprinting widget.
 *
 * Silently validates legitimate human visitors in the background without captchas or interruptions.
 * Detects headless browsers (Selenium, Puppeteer, Playwright).
 *
 * Usage in layouts:
 * ```php
 * <?= \larikmc\antibot\widgets\AntibotWidget::widget() ?>
 * ```
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class AntibotWidget extends Widget
{
    /**
     * @var string Component ID in Yii::$app.
     */
    public string $componentId = 'antibot';

    /**
     * @var bool Whether to enable background client challenge.
     */
    public bool $autoVerify = true;

    /**
     * {@inheritdoc}
     */
    public function run(): string
    {
        if (Yii::$app === null) {
            return '';
        }

        $request = Yii::$app->request;
        $ip = $request->getUserIP() ?? '127.0.1.1';
        $ua = $request->getUserAgent() ?? '';

        /** @var Antibot|null $antibot */
        $antibot = Yii::$app->has($this->componentId) ? Yii::$app->get($this->componentId) : null;
        if ($antibot !== null && !$antibot->enabled) {
            return '';
        }

        $secretKey = $antibot !== null ? $antibot->getResolvedSecretKey() : (Yii::$app->request->cookieValidationKey ?: 'antibot_secret');

        // Check if visitor already has a valid token cookie
        $cookieToken = (string) $request->cookies->getValue(TokenService::COOKIE_NAME, '');
        if (TokenService::validateToken($cookieToken, $ip, $ua, $secretKey)) {
            return '<!-- antibot: verified -->';
        }

        if (!$this->autoVerify) {
            return '';
        }

        $challenge = TokenService::generateJsChallenge($ip, $secretKey);
        $options = [
            'seed' => $challenge['seed'],
            'target' => $challenge['target'],
            'verifyUrl' => $request->getAbsoluteUrl(),
        ];

        $jsonOptions = Json::encode($options);

        return <<<HTML
<script>
(function() {
    try {
        var opt = {$jsonOptions};
        // Detect headless browsers (Puppeteer, Selenium, Playwright)
        if (navigator.webdriver) { return; }
        if (window.outerWidth === 0 && window.outerHeight === 0) { return; }

        var verified = false;
        function sendVerify() {
            if (verified) return;
            verified = true;

            var fd = new FormData();
            fd.append('antibot_challenge_verify', '1');
            fd.append('seed', opt.seed);
            fd.append('answer', opt.target);

            if (window.fetch) {
                fetch(opt.verifyUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).catch(function() {});
            } else {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', opt.verifyUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(fd);
            }
        }

        // Verify after first human interaction or brief idle
        var events = ['pointerdown', 'touchstart', 'scroll', 'keydown'];
        function onInteract() {
            events.forEach(function(e) { window.removeEventListener(e, onInteract); });
            sendVerify();
        }
        events.forEach(function(e) { window.addEventListener(e, onInteract, { passive: true, once: true }); });

        if ('requestIdleCallback' in window) {
            requestIdleCallback(function() { setTimeout(sendVerify, 800); });
        } else {
            setTimeout(sendVerify, 1500);
        }
    } catch(e) {}
})();
</script>
HTML;
    }
}
