<?php

declare(strict_types=1);

namespace larikmc\antibot;

use larikmc\antibot\services\BotDetector;
use larikmc\antibot\services\IpMatcher;
use larikmc\antibot\services\RateLimiter;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\StorageService;
use larikmc\antibot\services\TokenService;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\helpers\Html;
use yii\web\Application as WebApplication;
use yii\web\Cookie;
use yii\web\Response;

/**
 * High-performance autonomous Anti-Bot protection component for Yii2.
 *
 * Provides multi-layer defense against bad scrapers, fake search bots,
 * aggressive AI crawlers, and HTTP flood requests with < 0.5 ms latency.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class Antibot extends Component implements BootstrapInterface
{
    public bool $enabled = true;
    public string $mode = StorageService::MODE_STANDARD;
    public bool $blockAiCrawlers = true;
    public bool $blockCommercialScrapers = true;
    public bool $blockCliAndLibraries = true;
    public bool $verifySearchBots = true;
    public bool $blockFakeSearchBots = true;
    public bool $rateLimitEnabled = true;
    public int $rateLimitMax = 60;
    public int $rateLimitWindow = 60;
    public bool $jsChallengeEnabled = true;
    public ?string $secretKey = null;
    public array $whitelist = ['127.0.0.1', '::1'];
    public array $blacklist = [];
    public ?string $storageFile = null;
    public ?string $eventsFile = null;
    public array $skipRoutes = ['assets', 'debug', 'gii'];
    public bool $trustProxyHeaders = true;
    public string $customBlockMessage = 'Access Denied: Automated bot activity detected.';

    private StorageService $_storage;

    public function init(): void
    {
        parent::init();

        $this->_storage = new StorageService();
        if ($this->storageFile !== null) {
            $this->_storage->storageFile = $this->storageFile;
        }
        if ($this->eventsFile !== null) {
            $this->_storage->eventsFile = $this->eventsFile;
        }

        // Apply settings from persistent JSON file if present
        $settings = $this->_storage->loadSettings();
        $this->enabled = (bool) ($settings['enabled'] ?? $this->enabled);
        $this->mode = (string) ($settings['mode'] ?? $this->mode);
        $this->blockAiCrawlers = (bool) ($settings['blockAiCrawlers'] ?? $this->blockAiCrawlers);
        $this->blockCommercialScrapers = (bool) ($settings['blockCommercialScrapers'] ?? $this->blockCommercialScrapers);
        $this->blockCliAndLibraries = (bool) ($settings['blockCliAndLibraries'] ?? $this->blockCliAndLibraries);
        $this->verifySearchBots = (bool) ($settings['verifySearchBots'] ?? $this->verifySearchBots);
        $this->blockFakeSearchBots = (bool) ($settings['blockFakeSearchBots'] ?? $this->blockFakeSearchBots);
        $this->rateLimitEnabled = (bool) ($settings['rateLimitEnabled'] ?? $this->rateLimitEnabled);
        $this->rateLimitMax = (int) ($settings['rateLimitMax'] ?? $this->rateLimitMax);
        $this->rateLimitWindow = (int) ($settings['rateLimitWindow'] ?? $this->rateLimitWindow);
        $this->jsChallengeEnabled = (bool) ($settings['jsChallengeEnabled'] ?? $this->jsChallengeEnabled);
        $this->whitelist = (array) ($settings['whitelist'] ?? $this->whitelist);
        $this->blacklist = (array) ($settings['blacklist'] ?? $this->blacklist);
        $this->customBlockMessage = (string) ($settings['customBlockMessage'] ?? $this->customBlockMessage);

        if (!empty($settings['secretKey'])) {
            $this->secretKey = (string) $settings['secretKey'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function bootstrap($app): void
    {
        if (!$app instanceof WebApplication || !$this->enabled) {
            return;
        }

        $app->on(WebApplication::EVENT_BEFORE_REQUEST, function (): void {
            $this->checkRequest();
        });
    }

    /**
     * Resolves the encryption key used for signing cookies and tokens.
     */
    public function getResolvedSecretKey(): string
    {
        if (!empty($this->secretKey)) {
            return $this->secretKey;
        }

        if (Yii::$app !== null && !empty(Yii::$app->request->cookieValidationKey)) {
            return Yii::$app->request->cookieValidationKey;
        }

        return 'larikmc_antibot_secret_default_key_change_in_config';
    }

    /**
     * Main inspection logic executed on beforeRequest.
     */
    public function checkRequest(): void
    {
        if (!$this->enabled || Yii::$app === null) {
            return;
        }

        $request = Yii::$app->request;
        $pathInfo = $request->getPathInfo();

        // Skip internal/static asset routes
        foreach ($this->skipRoutes as $skip) {
            if ($skip !== '' && (str_starts_with($pathInfo, $skip . '/') || $pathInfo === $skip)) {
                return;
            }
        }

        $clientIp = null;
        if ($this->trustProxyHeaders) {
            $clientIp = $request->headers->get('cf-connecting-ip')
                ?? $request->headers->get('x-real-ip')
                ?? $request->headers->get('x-forwarded-for');
            if ($clientIp !== null && str_contains($clientIp, ',')) {
                $clientIp = trim(explode(',', $clientIp)[0]);
            }
        }
        $ip = $clientIp ?? $request->getUserIP() ?? '127.0.0.1';
        $ua = $request->getUserAgent() ?? '';
        $url = $request->getAbsoluteUrl();

        // 1. Check JS Challenge verification submission
        if ($request->isPost && $request->post('antibot_challenge_verify') === '1') {
            $this->handleChallengeSubmission($ip, $ua);
            return;
        }

        // 2. Whitelist Check
        if (IpMatcher::match($ip, $this->whitelist)) {
            return;
        }

        // 3. Blacklist Check
        if (IpMatcher::match($ip, $this->blacklist)) {
            $this->blockRequest('Blacklisted IP address or subnet', $ip, $ua, $url);
            return;
        }

        // 4. Search Engine Bot Check (with Reverse DNS verification)
        $searchEngine = BotDetector::detectSearchBot($ua);
        if ($searchEngine !== null) {
            if ($this->verifySearchBots) {
                $isAuthentic = ReverseDnsVerifier::verify($ip, $searchEngine);
                if ($isAuthentic) {
                    $this->_storage->logEvent([
                        'ip' => $ip,
                        'userAgent' => $ua,
                        'action' => 'allowed_search_bot',
                        'reason' => 'Authentic Search Engine: ' . ucfirst($searchEngine),
                        'url' => $url,
                    ]);
                    return; // NEVER block real search engine bots
                }

                if ($this->blockFakeSearchBots) {
                    $this->blockRequest('Fake search bot spoofing ' . ucfirst($searchEngine), $ip, $ua, $url);
                    return;
                }
            } else {
                return;
            }
        }

        // 5. AI Crawlers Check
        if ($this->blockAiCrawlers) {
            $aiCrawler = BotDetector::detectAiCrawler($ua);
            if ($aiCrawler !== null) {
                $this->blockRequest('AI crawler blocked: ' . $aiCrawler, $ip, $ua, $url);
                return;
            }
        }

        // 6. Commercial Aggressive Scrapers Check
        if ($this->blockCommercialScrapers) {
            $scraper = BotDetector::detectCommercialScraper($ua);
            if ($scraper !== null) {
                $this->blockRequest('Commercial scraper blocked: ' . $scraper, $ip, $ua, $url);
                return;
            }
        }

        // 7. CLI utilities and HTTP libraries
        if ($this->blockCliAndLibraries) {
            $cliTool = BotDetector::detectCliOrLibrary($ua);
            if ($cliTool !== null) {
                $this->blockRequest('Automated CLI or HTTP library: ' . $cliTool, $ip, $ua, $url);
                return;
            }
        }

        // 8. Header anomalies
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(',', $values);
        }
        $anomaly = BotDetector::detectHeaderAnomalies($ua, $headers);
        if ($anomaly !== null) {
            $this->blockRequest('Header anomaly: ' . $anomaly, $ip, $ua, $url);
            return;
        }

        // 9. Rate Limiter Check
        if ($this->rateLimitEnabled) {
            $rl = RateLimiter::check($ip, $this->rateLimitMax, $this->rateLimitWindow);
            if ($rl['exceeded']) {
                $this->blockRequest('Rate limit exceeded: too many requests', $ip, $ua, $url, 429);
                return;
            }
        }

        // 10. Strict Mode JS Challenge Check
        if ($this->mode === StorageService::MODE_STRICT) {
            $cookieToken = (string) $request->cookies->getValue(TokenService::COOKIE_NAME, '');
            $isValid = TokenService::validateToken($cookieToken, $ip, $ua, $this->getResolvedSecretKey());

            if (!$isValid) {
                $this->renderChallengePage($ip);
                return;
            }
        }
    }

    /**
     * Issues verified cookie to the visitor.
     */
    public function issueVerifiedCookie(string $ip, string $userAgent): void
    {
        $token = TokenService::generateToken($ip, $userAgent, $this->getResolvedSecretKey());

        $cookie = new Cookie([
            'name' => TokenService::COOKIE_NAME,
            'value' => $token,
            'expire' => time() + TokenService::DEFAULT_TTL,
            'httpOnly' => true,
            'secure' => Yii::$app->request->isSecureConnection,
            'path' => '/',
        ]);

        Yii::$app->response->cookies->add($cookie);
    }

    /**
     * Handles background or form verification of the client challenge.
     */
    protected function handleChallengeSubmission(string $ip, string $ua): void
    {
        $request = Yii::$app->request;
        $seed = (string) $request->post('seed', '');
        $answer = (string) $request->post('answer', '');

        $isValid = TokenService::verifyJsChallenge($seed, $answer, $this->getResolvedSecretKey());
        if ($isValid) {
            $this->issueVerifiedCookie($ip, $ua);

            if ($request->isAjax) {
                Yii::$app->response->format = Response::FORMAT_JSON;
                Yii::$app->response->data = ['success' => true];
            } else {
                Yii::$app->response->redirect($request->getAbsoluteUrl());
            }
            Yii::$app->end();
        }

        $this->blockRequest('Failed JS Challenge verification', $ip, $ua, $request->getAbsoluteUrl());
    }

    /**
     * Blocks the request with an HTTP status and HTML notice.
     */
    public function blockRequest(string $reason, string $ip, string $ua, string $url, int $statusCode = 403): void
    {
        $this->_storage->logEvent([
            'ip' => $ip,
            'userAgent' => $ua,
            'action' => 'blocked',
            'reason' => $reason,
            'url' => $url,
        ]);

        // If in monitoring mode, only log without aborting
        if ($this->mode === StorageService::MODE_MONITORING) {
            Yii::info(sprintf('[Antibot Monitoring] Block skipped for %s: %s', $ip, $reason), __METHOD__);
            return;
        }

        $response = Yii::$app->response;
        $response->statusCode = $statusCode;
        $response->format = Response::FORMAT_HTML;

        $safeReason = Html::encode($reason);
        $safeIp = Html::encode($ip);
        $safeMessage = Html::encode($this->customBlockMessage);

        $response->content = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ ограничен / Access Restricted</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 520px; width: 100%; padding: 36px 32px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); text-align: center; }
        .icon { width: 64px; height: 64px; background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; color: #ef4444; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 20px; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 12px; color: #ffffff; }
        p { font-size: 14px; line-height: 1.6; color: #94a3b8; margin-bottom: 24px; }
        .details { background: #0f172a; border-radius: 10px; padding: 14px 16px; text-align: left; font-size: 13px; color: #cbd5e1; border: 1px solid #1e293b; }
        .details div { margin-bottom: 6px; }
        .details div:last-child { margin-bottom: 0; }
        .details strong { color: #f1f5f9; }
        .footer { margin-top: 24px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🛡️</div>
        <h1>Доступ ограничен</h1>
        <p>{$safeMessage}</p>
        <div class="details">
            <div><strong>Причина:</strong> {$safeReason}</div>
            <div><strong>Ваш IP:</strong> <code>{$safeIp}</code></div>
            <div><strong>Время:</strong> <code>{$this->getCurrentDateTime()}</code></div>
        </div>
        <div class="footer">Защищено модулем Yii2 Antibot</div>
    </div>
</body>
</html>
HTML;

        $response->send();
        Yii::$app->end();
    }

    /**
     * Renders a lightweight seamless JS challenge screen for strict mode.
     */
    protected function renderChallengePage(string $ip): void
    {
        $challenge = TokenService::generateJsChallenge($ip, $this->getResolvedSecretKey());
        $seed = Html::encode($challenge['seed']);
        $target = Html::encode($challenge['target']);

        $response = Yii::$app->response;
        $response->statusCode = 200;
        $response->format = Response::FORMAT_HTML;

        $response->content = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка браузера...</title>
    <style>
        body { font-family: sans-serif; background: #0f172a; color: #cbd5e1; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
        .box { padding: 40px; background: #1e293b; border-radius: 12px; border: 1px solid #334155; }
        .spinner { width: 40px; height: 40px; border: 4px solid #334155; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 20px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="box">
        <div class="spinner"></div>
        <h2>Проверка безопасности соединения</h2>
        <p style="font-size: 14px; color: #94a3b8;">Пожалуйста, подождите пару секунд...</p>
    </div>
    <form id="challengeForm" method="post" style="display:none;">
        <input type="hidden" name="antibot_challenge_verify" value="1">
        <input type="hidden" name="seed" value="{$seed}">
        <input type="hidden" name="answer" value="{$target}">
    </form>
    <script>
        (function() {
            // Verify real browser features
            if (navigator.webdriver) { return; }
            setTimeout(function() {
                document.getElementById('challengeForm').submit();
            }, 600);
        })();
    </script>
</body>
</html>
HTML;

        $response->send();
        Yii::$app->end();
    }

    public function getStorage(): StorageService
    {
        return $this->_storage;
    }

    private function getCurrentDateTime(): string
    {
        return date('d.m.Y H:i:s');
    }
}
