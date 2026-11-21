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

        // Exempt authentic search and ad crawlers ONLY IF verified via ReverseDnsVerifier AND policy is not block
        if ($ua !== '') {
            $candBot = \larikmc\antibot\services\BotCatalog::identify($ua);
            if ($candBot !== null) {
                $candCat = $candBot['data']['category'] ?? '';
                if ($candCat === \larikmc\antibot\services\BotCatalog::CATEGORY_SEARCH || $candCat === \larikmc\antibot\services\BotCatalog::CATEGORY_AD) {
                    $isBlocked = false;
                    if ($antibot !== null && $antibot->resolveBotPolicy($candBot['key'], $candBot['data']) === 'block') {
                        $isBlocked = true;
                    }
                    if (!$isBlocked) {
                        $vStatus = \larikmc\antibot\services\ReverseDnsVerifier::checkBot($ip, $candBot['data']);
                        if ($vStatus === \larikmc\antibot\services\ReverseDnsVerifier::STATUS_VERIFIED) {
                            return '<!-- antibot: crawler (verified) -->';
                        }
                    }
                }
            }
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
        var retried = false;

        try {
            if (sessionStorage.getItem('__ab_v') === '1') {
                verified = true;
            }
        } catch(e) {}

        function sendVerify(currentSeed, currentTarget) {
            if (verified) return;

            var fd = new FormData();
            fd.append('antibot_challenge_verify', '1');
            fd.append('seed', currentSeed || opt.seed);
            fd.append('answer', currentTarget || opt.target);

            function handleResponse(data) {
                if (!data) return;
                if (data.success) {
                    verified = true;
                    try { sessionStorage.setItem('__ab_v', '1'); } catch(e) {}
                } else if (data.expired && data.seed && data.target && !retried) {
                    retried = true;
                    // Safe retry for slow reader without penalty
                    setTimeout(function() {
                        sendVerify(data.seed, data.target);
                    }, 100);
                }
            }

            if (window.fetch) {
                fetch(opt.verifyUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function(r) { return r.json(); }).then(handleResponse).catch(function() {});
            } else {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', opt.verifyUrl, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    try { handleResponse(JSON.parse(xhr.responseText)); } catch(e) {}
                };
                xhr.send(fd);
            }
        }

        // BFCache (Back-Forward Cache) safety
        window.addEventListener('pageshow', function(e) {
            try {
                if (sessionStorage.getItem('__ab_v') === '1') {
                    verified = true;
                }
            } catch(err) {}
        });

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
