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
use yii\web\Application as WebApplication;
use yii\web\Response;

/**
 * Universal Smart Anti-Bot & AI Crawler Shield Module for Yii 2.
 *
 * Provides:
 * - Reverse DNS verification for legitimate search crawlers (Google, Yandex, Bing).
 * - Detection and mitigation of spoofed / fake search engines.
 * - Granular control over AI crawlers (Search & Referral vs Training scrapers).
 * - Mitigation of commercial SEO parsers, CLI tools, and HTTP libraries.
 * - Two-phase lifecycle: Global URI checks and Action-level rate limits.
 * - Anti-DNS-DoS budget and safe proxy IP resolution.
 * - Non-blocking JSONL event logging and accurate multi-tier statistics.
 * - Machine/API request detection with clean JSON 403 / 429 responses.
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
    public int $rateLimitBanDuration = 300;
    public string $rateLimitAdapter = RateLimiter::ADAPTER_CACHE;

    public bool $jsChallengeEnabled = true;
    public ?string $secretKey = null;

    public array $whitelist = ['127.0.0.1', '::1'];
    public array $blacklist = [];

    /**
     * @var array<int, string> Explicit list of trusted reverse proxy IPs or CIDR subnets.
     * When empty, proxy headers (X-Forwarded-For, CF-Connecting-IP) are NEVER trusted, preventing IP spoofing.
     */
    public array $trustedProxies = [];

    /**
     * @var array<int, string> Headers to inspect when REMOTE_ADDR is in $trustedProxies.
     */
    public array $ipHeaders = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'];

    /**
     * @var array<string, string> Individual AI crawler policies (name => 'allow'|'monitor'|'throttle'|'block').
     */
    public array $aiCrawlerPolicies = [];

    /**
     * @var array<int, array{pattern: string, methods?: array, maxRequests: int, window: int, action?: string}>
     */
    public array $routeRules = [];

    /**
     * @var bool Enables local behavioral pattern analyzer.
     */
    public bool $behaviorAnalysisEnabled = false;

    /**
     * @var bool Enables weighted load budget rate limiter.
     */
    public bool $weightedRateLimitEnabled = false;

    /**
     * @var int Total points budget per client window.
     */
    public int $weightedRateLimitBudget = 150;

    /**
     * @var int Budget window in seconds.
     */
    public int $weightedRateLimitWindow = 60;

    /**
     * @var array<string, int> Route cost weights mapping (pattern => points).
     */
    public array $routeWeights = [];

    /**
     * @var bool Whether Proof-of-Work (PoW) computation challenge is enabled.
     */
    public bool $powEnabled = false;

    /**
     * @var string Difficulty level for Proof-of-Work ('light', 'medium', 'strong').
     */
    public string $powDifficulty = \larikmc\antibot\services\pow\PowService::DIFFICULTY_MEDIUM;

    /**
     * @var bool Whether invisible Honey URL traps are enabled.
     */
    public bool $honeyTrapsEnabled = false;

    /**
     * @var string Relative route path for honey URL traps.
     */
    public string $trapRoute = 'antibot/trap';

    public ?string $storageFile = null;
    public ?string $eventsFile = null;
    public array $skipRoutes = ['assets', 'debug', 'gii'];
    public string $customBlockMessage = 'Access Denied: Automated bot activity detected.';

    private StorageService $_storage;
    private bool $_hasSecretKeyWarning = false;

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
        $this->rateLimitBanDuration = (int) ($settings['rateLimitBanDuration'] ?? $this->rateLimitBanDuration);
        $this->rateLimitAdapter = (string) ($settings['rateLimitAdapter'] ?? $this->rateLimitAdapter);
        $this->jsChallengeEnabled = (bool) ($settings['jsChallengeEnabled'] ?? $this->jsChallengeEnabled);
        $this->whitelist = (array) ($settings['whitelist'] ?? $this->whitelist);
        $this->blacklist = (array) ($settings['blacklist'] ?? $this->blacklist);
        $this->trustedProxies = (array) ($settings['trustedProxies'] ?? $this->trustedProxies);
        $this->ipHeaders = (array) ($settings['ipHeaders'] ?? $this->ipHeaders);
        $this->aiCrawlerPolicies = (array) ($settings['aiCrawlerPolicies'] ?? $this->aiCrawlerPolicies);
        $this->routeRules = (array) ($settings['routeRules'] ?? $this->routeRules);
        $this->behaviorAnalysisEnabled = (bool) ($settings['behaviorAnalysisEnabled'] ?? $this->behaviorAnalysisEnabled);
        $this->weightedRateLimitEnabled = (bool) ($settings['weightedRateLimitEnabled'] ?? $this->weightedRateLimitEnabled);
        $this->weightedRateLimitBudget = (int) ($settings['weightedRateLimitBudget'] ?? $this->weightedRateLimitBudget);
        $this->weightedRateLimitWindow = (int) ($settings['weightedRateLimitWindow'] ?? $this->weightedRateLimitWindow);
        $this->routeWeights = (array) ($settings['routeWeights'] ?? $this->routeWeights);
        $this->powEnabled = (bool) ($settings['powEnabled'] ?? $this->powEnabled);
        $this->powDifficulty = (string) ($settings['powDifficulty'] ?? $this->powDifficulty);
        $this->honeyTrapsEnabled = (bool) ($settings['honeyTrapsEnabled'] ?? $this->honeyTrapsEnabled);
        $this->trapRoute = (string) ($settings['trapRoute'] ?? $this->trapRoute);
        $this->customBlockMessage = (string) ($settings['customBlockMessage'] ?? $this->customBlockMessage);

        if (!empty($settings['secretKey'])) {
            $this->secretKey = (string) $settings['secretKey'];
        }

        // Configure RateLimiter adapter
        RateLimiter::$adapter = $this->rateLimitAdapter;
    }

    /**
     * {@inheritdoc}
     */
    public function bootstrap($app): void
    {
        if (!$app instanceof WebApplication || !$this->enabled) {
            return;
        }

        // Phase 1: Fast global URI and IP checks before routing
        $app->on(WebApplication::EVENT_BEFORE_REQUEST, function (): void {
            $this->checkRequest();
        });

        // Phase 2: Route and controller action checks after routing
        $app->on(WebApplication::EVENT_BEFORE_ACTION, function ($event): void {
            $this->checkAction($event);
        });
    }

    /**
     * Safely resolves the client IP address.
     * Prevents IP spoofing by validating $_SERVER['REMOTE_ADDR'] against $trustedProxies.
     */
    public function resolveClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Strict: If no trusted proxies configured, never trust proxy headers
        if (empty($this->trustedProxies) || !IpMatcher::match($remoteAddr, $this->trustedProxies)) {
            return $this->sanitizeIp($remoteAddr);
        }

        $request = Yii::$app !== null ? Yii::$app->request : null;
        if ($request === null) {
            return $this->sanitizeIp($remoteAddr);
        }

        foreach ($this->ipHeaders as $header) {
            $val = $request->headers->get($header);
            if ($val !== null && trim($val) !== '') {
                $ips = array_map('trim', explode(',', $val));
                // Walk from right to left to find the first untrusted client IP
                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    $cand = $ips[$i];
                    if (!IpMatcher::match($cand, $this->trustedProxies)) {
                        $sanitized = $this->sanitizeIp($cand);
                        if ($sanitized !== '') {
                            return $sanitized;
                        }
                    }
                }
                $sanitized = $this->sanitizeIp($ips[0]);
                if ($sanitized !== '') {
                    return $sanitized;
                }
            }
        }

        return $this->sanitizeIp($remoteAddr);
    }

    private function sanitizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '127.0.0.1';
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

        // Safe deterministic machine-local fallback (different for every server installation)
        $this->_hasSecretKeyWarning = true;
        $base = Yii::$app !== null ? Yii::$app->getBasePath() : __DIR__;
        return hash('sha256', 'antibot_salt_' . $base);
    }

    public function hasSecretKeyWarning(): bool
    {
        return $this->_hasSecretKeyWarning;
    }

    /**
     * Phase 1: Fast global inspection executed on beforeRequest.
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

        $ip = $this->resolveClientIp();
        $ua = $request->getUserAgent() ?? '';
        $url = $request->getAbsoluteUrl();

        // 1. Check JS Challenge verification submission
        if ($request->isPost && $request->post('antibot_challenge_verify') === '1') {
            $this->handleChallengeSubmission($ip, $ua);
            return;
        }

        // 1b. Check Proof-of-Work Challenge submission
        if ($request->isPost && $request->post('antibot_pow_solve') === '1') {
            $this->handlePowSubmission($ip, $ua);
            return;
        }

        // 1c. Check Honey URL Trap access
        if ($this->honeyTrapsEnabled && ($pathInfo === $this->trapRoute || $pathInfo === ltrim($this->trapRoute, '/'))) {
            $this->handleTrapHit($ip, $ua);
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

        // 4. Legitimate vs Fake Search Bot Verification
        $searchEngine = BotDetector::detectSearchBot($ua);
        if ($searchEngine !== null) {
            if ($this->verifySearchBots) {
                $dnsStatus = ReverseDnsVerifier::check($ip, $searchEngine);

                if ($dnsStatus === ReverseDnsVerifier::STATUS_VERIFIED) {
                    $this->_storage->logEvent([
                        'ip' => $ip,
                        'userAgent' => $ua,
                        'action' => 'allowed_search_bot',
                        'reason' => 'Authentic Search Engine: ' . ucfirst($searchEngine),
                        'url' => $url,
                    ]);
                    return; // Pass verified search engine through
                }

                if ($dnsStatus === ReverseDnsVerifier::STATUS_FAKE && $this->blockFakeSearchBots) {
                    $this->blockRequest('Fake search bot spoofing ' . ucfirst($searchEngine), $ip, $ua, $url);
                    return;
                }
                // STATUS_UNKNOWN: do NOT block as fake; proceed with standard checks
            } else {
                return;
            }
        }

        // 5. AI Crawlers (Search vs Training Scrapers)
        $aiBot = BotDetector::detectAiCrawler($ua);
        if ($aiBot !== null) {
            $botName = $aiBot['name'];
            $policy = $this->aiCrawlerPolicies[$botName] ?? null;

            if ($policy === null) {
                // Default: allow AI Search bots to preserve referral traffic, block training scrapers
                $policy = $aiBot['type'] === 'search' ? 'allow' : ($this->blockAiCrawlers ? 'block' : 'allow');
            }

            if ($policy === 'block') {
                $this->blockRequest('AI crawler blocked: ' . $botName, $ip, $ua, $url);
                return;
            } elseif ($policy === 'monitor') {
                $this->_storage->logEvent([
                    'ip' => $ip,
                    'userAgent' => $ua,
                    'action' => 'monitored',
                    'reason' => 'AI crawler monitored: ' . $botName,
                    'url' => $url,
                ]);
            }
        }

        // 6. Commercial SEO Scrapers
        $scraper = BotDetector::detectCommercialScraper($ua);
        if ($scraper !== null && $this->blockCommercialScrapers) {
            $this->blockRequest('Commercial scraper blocked: ' . $scraper, $ip, $ua, $url);
            return;
        }

        // 7. CLI tools & automated HTTP libraries
        $cliTool = BotDetector::detectCliAndLibraries($ua);
        if ($cliTool !== null && $this->blockCliAndLibraries) {
            $this->blockRequest('Automated CLI or HTTP library: ' . $cliTool, $ip, $ua, $url);
            return;
        }

        // 8. Anomalous HTTP headers
        $anomReason = BotDetector::detectAnomalousHeaders($request->headers->toArray());
        if ($anomReason !== null && $this->blockCliAndLibraries) {
            $this->blockRequest($anomReason, $ip, $ua, $url);
            return;
        }

        // 9. Verified Visitor Cookie Check
        $secretKey = $this->getResolvedSecretKey();
        $cookieToken = $request->cookies->getValue(TokenService::COOKIE_NAME);
        $isVerifiedVisitor = $cookieToken !== null && TokenService::validateToken($cookieToken, $ip, $ua, $secretKey);

        // 10. URI pattern rules & Rate Limiting
        $matchedRule = $this->findMatchingUriRule($pathInfo, $request->getMethod());
        $maxReq = $matchedRule['maxRequests'] ?? $this->rateLimitMax;
        $win = $matchedRule['window'] ?? $this->rateLimitWindow;

        if ($this->rateLimitEnabled) {
            $limitKey = $matchedRule !== null ? sprintf('%s_uri_%s', $ip, md5($pathInfo)) : $ip;
            $limitResult = RateLimiter::check($limitKey, $maxReq, $win, $this->rateLimitBanDuration);

            if ($limitResult['exceeded']) {
                $this->throttleRequest(
                    sprintf('Rate limit exceeded: %d req/%ds', $maxReq, $win),
                    $ip,
                    $limitResult['retryAfter']
                );
                return;
            }
        }

        // 11. Weighted Load-Budget Limiting
        if ($this->weightedRateLimitEnabled) {
            $cost = \larikmc\antibot\services\limiter\WeightedRateLimiter::resolveCost($pathInfo, $this->routeWeights);
            $budgetRes = \larikmc\antibot\services\limiter\WeightedRateLimiter::check($ip, $cost, $this->weightedRateLimitBudget, $this->weightedRateLimitWindow);

            if ($budgetRes['exceeded']) {
                $this->throttleRequest(
                    sprintf('Load budget exceeded (%d/%d pts in %ds)', $budgetRes['current'], $this->weightedRateLimitBudget, $this->weightedRateLimitWindow),
                    $ip,
                    $budgetRes['retryAfter']
                );
                return;
            }
        }

        // 12. Behavioral Intelligence Analysis
        if ($this->behaviorAnalysisEnabled && !$isVerifiedVisitor) {
            $isSearch = str_contains($pathInfo, 'search') || $request->get('q') !== null || $request->get('query') !== null;
            $behavior = \larikmc\antibot\services\behavior\BehaviorAnalyzer::analyze($ip, $pathInfo, $isSearch);

            if (!empty($behavior['signals'])) {
                $risk = $behavior['risk'];
                if ($risk['level'] === 'high') {
                    if ($this->powEnabled && !$this->isMachineOrApiRequest()) {
                        $pow = \larikmc\antibot\services\pow\PowService::generateChallenge($ip, $secretKey, $this->powDifficulty);
                        $this->_storage->logEvent([
                            'ip' => $ip,
                            'userAgent' => $ua,
                            'action' => 'challenged',
                            'reason' => 'Proof-of-Work triggered by high risk score (' . $risk['score'] . ')',
                            'url' => $url,
                        ]);
                        $html = \larikmc\antibot\services\pow\PowService::renderChallengeHtml(
                            $pow['seed'],
                            $pow['target'],
                            $request->getAbsoluteUrl(),
                            'Подозрительная активность. Проверка браузера...'
                        );
                        $response = Yii::$app->response;
                        $response->format = Response::FORMAT_HTML;
                        $response->content = $html;
                        $response->send();
                        exit(0);
                    } elseif ($this->mode === StorageService::MODE_STRICT && $this->jsChallengeEnabled) {
                        if (!$this->isMachineOrApiRequest()) {
                            $this->renderChallengeScreen($ip, $ua, $secretKey);
                            exit(0);
                        }
                    } else {
                        $this->_storage->logEvent([
                            'ip' => $ip,
                            'userAgent' => $ua,
                            'action' => 'monitored',
                            'reason' => 'Behavior Anomaly: ' . implode(', ', $behavior['signals']) . ' (Risk: ' . $risk['score'] . ')',
                            'url' => $url,
                        ]);
                    }
                } elseif ($risk['level'] === 'medium') {
                    $this->_storage->logEvent([
                        'ip' => $ip,
                        'userAgent' => $ua,
                        'action' => 'monitored',
                        'reason' => 'Suspicious Behavior: ' . implode(', ', $behavior['signals']),
                        'url' => $url,
                    ]);
                }
            }
        }

        // 13. Strict Mode Challenge
        if ($this->mode === StorageService::MODE_STRICT && !$isVerifiedVisitor && $this->jsChallengeEnabled) {
            if (!$this->isMachineOrApiRequest()) {
                $this->renderChallengeScreen($ip, $ua, $secretKey);
                exit(0);
            }
        }

        // Tag request to prevent duplicate checks in Phase 2
        Yii::$app->params['__antibot_checked_ip'] = $ip;
    }

    /**
     * Phase 2: Action-level inspection executed on beforeAction.
     */
    public function checkAction($event): void
    {
        if (!$this->enabled || Yii::$app === null || !isset($event->action)) {
            return;
        }

        $action = $event->action;
        $route = $action->getUniqueId();
        $request = Yii::$app->request;

        $matchedRule = $this->findMatchingRouteRule($route, $request->getMethod());
        if ($matchedRule === null) {
            return;
        }

        $ip = $this->resolveClientIp();
        $key = sprintf('%s_act_%s', $ip, md5($route));
        $maxReq = (int) ($matchedRule['maxRequests'] ?? 30);
        $win = (int) ($matchedRule['window'] ?? 60);
        $actionType = (string) ($matchedRule['action'] ?? 'throttle');

        $limitResult = RateLimiter::check($key, $maxReq, $win, $this->rateLimitBanDuration);
        if ($limitResult['exceeded']) {
            if ($actionType === 'block') {
                $this->blockRequest(
                    sprintf('Route rule blocked on "%s"', $route),
                    $ip,
                    $request->getUserAgent() ?? '',
                    $request->getAbsoluteUrl()
                );
            } else {
                $this->throttleRequest(
                    sprintf('Action rate limit exceeded on "%s" (%d req/%ds)', $route, $maxReq, $win),
                    $ip,
                    $limitResult['retryAfter']
                );
            }
            $event->isValid = false;
        }
    }

    /**
     * Blocks the request with HTTP 403.
     */
    public function blockRequest(string $reason, string $ip, string $ua, string $url): void
    {
        // In Monitoring mode, record and allow through without blocking
        if ($this->mode === StorageService::MODE_MONITORING) {
            $this->_storage->logEvent([
                'ip' => $ip,
                'userAgent' => $ua,
                'action' => 'monitored',
                'reason' => $reason,
                'url' => $url,
            ]);
            return;
        }

        $this->_storage->logEvent([
            'ip' => $ip,
            'userAgent' => $ua,
            'action' => 'blocked',
            'reason' => $reason,
            'url' => $url,
        ]);

        \larikmc\antibot\services\behavior\BehaviorAnalyzer::recordViolation($ip);

        if (Yii::$app === null) {
            http_response_code(403);
            exit(0);
        }

        $response = Yii::$app->response;
        $response->statusCode = 403;

        // Machine/API request: return clean JSON without HTML leakage
        if ($this->isMachineOrApiRequest()) {
            $response->format = Response::FORMAT_JSON;
            $response->data = [
                'error' => 'Forbidden',
                'message' => $this->customBlockMessage,
                'status' => 403,
            ];
            $response->send();
            exit(0);
        }

        // Normal browser: render dark mode branded HTML block page
        $response->format = Response::FORMAT_HTML;
        $response->content = $this->renderBlockHtml($reason, $ip);
        $response->send();
        exit(0);
    }

    /**
     * Throttles the request with HTTP 429 Too Many Requests.
     */
    public function throttleRequest(string $reason, string $ip, int $retryAfter = 60): void
    {
        if ($this->mode === StorageService::MODE_MONITORING) {
            $this->_storage->logEvent([
                'ip' => $ip,
                'userAgent' => Yii::$app->request->getUserAgent() ?? '',
                'action' => 'monitored',
                'reason' => $reason,
                'url' => Yii::$app->request->getAbsoluteUrl(),
            ]);
            return;
        }

        $this->_storage->logEvent([
            'ip' => $ip,
            'userAgent' => Yii::$app->request->getUserAgent() ?? '',
            'action' => 'throttled',
            'reason' => $reason,
            'url' => Yii::$app->request->getAbsoluteUrl(),
        ]);

        \larikmc\antibot\services\behavior\BehaviorAnalyzer::recordViolation($ip);

        $response = Yii::$app->response;
        $response->statusCode = 429;
        $response->headers->set('Retry-After', (string) max(1, $retryAfter));

        if ($this->isMachineOrApiRequest()) {
            $response->format = Response::FORMAT_JSON;
            $response->data = [
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => max(1, $retryAfter),
                'status' => 429,
            ];
            $response->send();
            exit(0);
        }

        $response->format = Response::FORMAT_HTML;
        $response->content = $this->renderBlockHtml($reason . ' (Повторите через ' . $retryAfter . ' сек.)', $ip, 429);
        $response->send();
        exit(0);
    }

    /**
     * Determines whether the incoming request expects a machine-readable response (AJAX/API).
     */
    public function isMachineOrApiRequest(): bool
    {
        $request = Yii::$app !== null ? Yii::$app->request : null;
        if ($request === null) {
            return false;
        }

        if ($request->isAjax) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');
        if (str_contains($accept, 'application/json') || str_contains($accept, 'application/xml')) {
            return true;
        }

        if ($request->headers->has('X-Requested-With')) {
            return true;
        }

        $path = $request->getPathInfo();
        if (str_starts_with($path, 'api/') || $path === 'api' || str_starts_with($path, 'webhook/')) {
            return true;
        }

        return false;
    }

    /**
     * Handles POST submission of solved JS Challenge.
     */
    protected function handleChallengeSubmission(string $ip, string $ua): void
    {
        $request = Yii::$app->request;
        $seed = (string) $request->post('seed', '');
        $answer = (string) $request->post('answer', '');
        $secretKey = $this->getResolvedSecretKey();

        if (TokenService::verifyJsChallenge($seed, $answer, $secretKey)) {
            $token = TokenService::generateToken($ip, $ua, $secretKey);
            $cookie = new \yii\web\Cookie([
                'name' => TokenService::COOKIE_NAME,
                'value' => $token,
                'expire' => time() + TokenService::DEFAULT_TTL,
                'httpOnly' => true,
                'secure' => $request->isSecureConnection,
                'sameSite' => 'Lax',
            ]);
            Yii::$app->response->cookies->add($cookie);

            $this->_storage->logEvent([
                'ip' => $ip,
                'userAgent' => $ua,
                'action' => 'challenge_passed',
                'reason' => 'JS Challenge Solved',
                'url' => $request->getAbsoluteUrl(),
            ]);

            $response = Yii::$app->response;
            $response->format = Response::FORMAT_JSON;
            $response->data = ['success' => true];
            $response->send();
            exit(0);
        }

        $this->blockRequest('JS Challenge verification failed or replayed', $ip, $ua, $request->getAbsoluteUrl());
    }

    /**
     * Handles client submission of solved Proof-of-Work challenge.
     */
    protected function handlePowSubmission(string $ip, string $ua): void
    {
        $request = Yii::$app->request;
        $seed = (string) $request->post('seed', '');
        $nonce = (string) $request->post('nonce', '');
        $secretKey = $this->getResolvedSecretKey();
        $cache = Yii::$app->has('cache') ? Yii::$app->get('cache') : null;

        $res = \larikmc\antibot\services\pow\PowService::validateSolution($seed, $nonce, $ip, $secretKey, $cache);
        if ($res['valid']) {
            $token = TokenService::generateToken($ip, $ua, $secretKey);
            $cookie = new \yii\web\Cookie([
                'name' => TokenService::COOKIE_NAME,
                'value' => $token,
                'expire' => time() + TokenService::DEFAULT_TTL,
                'httpOnly' => true,
                'secure' => $request->isSecureConnection,
                'sameSite' => 'Lax',
            ]);
            Yii::$app->response->cookies->add($cookie);

            $this->_storage->logEvent([
                'ip' => $ip,
                'userAgent' => $ua,
                'action' => 'pow_solved',
                'reason' => 'Proof-of-Work Challenge Solved',
                'url' => $request->getAbsoluteUrl(),
            ]);

            if ($cache !== null && $cache instanceof \yii\caching\CacheInterface) {
                $count = (int) $cache->get('antibot_stat_pow_solved');
                $cache->set('antibot_stat_pow_solved', $count + 1, 86400 * 30);
            }

            $response = Yii::$app->response;
            if ($this->isMachineOrApiRequest()) {
                $response->format = Response::FORMAT_JSON;
                $response->data = ['success' => true, 'message' => 'PoW challenge solved'];
                $response->send();
                exit(0);
            }

            $cleanUrl = preg_replace('/[?&]antibot_pow_solve=[^&]*/', '', $request->getAbsoluteUrl());
            $response->redirect($cleanUrl);
            $response->send();
            exit(0);
        }

        $this->blockRequest('PoW solution rejected: ' . ($res['error'] ?? 'invalid'), $ip, $ua, $request->getAbsoluteUrl());
    }

    /**
     * Handles incoming access to the Honey URL Trap.
     */
    protected function handleTrapHit(string $ip, string $ua): void
    {
        $res = \larikmc\antibot\services\traps\HoneyUrlService::handleTrapHit($ip, $ua, $this);
        if ($res['penalized']) {
            \larikmc\antibot\services\behavior\BehaviorAnalyzer::recordViolation($ip);
        }

        $response = Yii::$app->response;
        $response->statusCode = 200;
        $response->format = Response::FORMAT_HTML;
        $response->content = '<!DOCTYPE html><html><head><meta name="robots" content="noindex,nofollow"><title>Archive</title></head><body></body></html>';
        $response->send();
        exit(0);
    }

    private function findMatchingUriRule(string $pathInfo, string $method): ?array
    {
        foreach ($this->routeRules as $rule) {
            $pattern = $rule['pattern'] ?? '';
            if ($pattern === '') {
                continue;
            }
            if (!empty($rule['methods']) && !in_array(strtoupper($method), array_map('strtoupper', $rule['methods']), true)) {
                continue;
            }
            if ($pathInfo === $pattern || str_starts_with($pathInfo, rtrim($pattern, '*') . '/')) {
                return $rule;
            }
        }
        return null;
    }

    private function findMatchingRouteRule(string $route, string $method): ?array
    {
        foreach ($this->routeRules as $rule) {
            $pattern = $rule['pattern'] ?? '';
            if ($pattern === '') {
                continue;
            }
            if (!empty($rule['methods']) && !in_array(strtoupper($method), array_map('strtoupper', $rule['methods']), true)) {
                continue;
            }
            if ($route === $pattern || fnmatch($pattern, $route)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Charges cost points against client load budget dynamically from application code.
     */
    public static function chargeCost(int $points, ?string $reason = null): void
    {
        if (Yii::$app !== null && Yii::$app->has('antibot')) {
            $comp = Yii::$app->get('antibot');
            if ($comp instanceof self && $comp->weightedRateLimitEnabled) {
                $ip = $comp->resolveClientIp();
                \larikmc\antibot\services\limiter\WeightedRateLimiter::check(
                    $ip,
                    max(1, $points),
                    $comp->weightedRateLimitBudget,
                    $comp->weightedRateLimitWindow
                );
            }
        }
    }

    public function getStorage(): StorageService
    {
        return $this->_storage;
    }

    protected function renderBlockHtml(string $reason, string $ip, int $statusCode = 403): string
    {
        $time = date('d.m.Y H:i:s');
        $title = $statusCode === 429 ? 'Слишком много запросов' : 'Доступ ограничен';
        $sub = $statusCode === 429 ? 'Превышен лимит обращений к сайту.' : htmlspecialchars($this->customBlockMessage, ENT_QUOTES, 'UTF-8');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} / Access Restricted</title>
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
        <h1>{$title}</h1>
        <p>{$sub}</p>
        <div class="details">
            <div><strong>Причина:</strong> {$safeReason}</div>
            <div><strong>Ваш IP:</strong> <code>{$safeIp}</code></div>
            <div><strong>Время:</strong> <code>{$time}</code></div>
        </div>
        <div class="footer">Защищено модулем Yii2 Antibot</div>
    </div>
</body>
</html>
HTML;
    }

    protected function renderChallengeScreen(string $ip, string $ua, string $secretKey): void
    {
        $challenge = TokenService::generateJsChallenge($ip, $secretKey);
        $seed = htmlspecialchars($challenge['seed'], ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars($challenge['target'], ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка безопасности / Security Check</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 480px; width: 100%; padding: 40px 32px; text-align: center; }
        .spinner { width: 44px; height: 44px; border: 3px solid rgba(59, 130, 246, 0.2); border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 24px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        h1 { font-size: 20px; margin-bottom: 8px; }
        p { color: #94a3b8; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h1>Проверка браузера...</h1>
        <p>Пожалуйста, подождите. Выполняется автоматическая проверка перед переходом на сайт.</p>
    </div>
    <script>
        setTimeout(function() {
            var form = new FormData();
            form.append('antibot_challenge_verify', '1');
            form.append('seed', '{$seed}');
            form.append('answer', '{$target}');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    window.location.reload();
                } else {
                    document.querySelector('h1').innerText = 'Ошибка верификации';
                }
            };
            xhr.send(form);
        }, 300);
    </script>
</body>
</html>
HTML;
    }
}
