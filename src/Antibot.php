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

    public const METRIC_SAMPLE_RESERVOIR_CAPACITY = 100;
    public const METRIC_RECORDING_MODE = 'best_effort';
    public const SAMPLE_STRATEGY_VERSION = 2;
    private static int $_metricDroppedRecords = 0;

    private StorageService $_storage;
    private bool $_hasSecretKeyWarning = false;

    private ?float $_inspectionStartHr = null;
    private string $_currentDecision = 'allowed';
    private bool $_inspectionRecorded = false;

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

        // Ensure in-process metrics are safely closed upon unexpected script exit
        register_shutdown_function(function (): void {
            $this->finishInspection($this->_currentDecision);
        });
    }

    /**
     * Safely resolves the client IP address.
     * Prevents IP spoofing by validating $_SERVER['REMOTE_ADDR'] against $trustedProxies.
     * Rejects invalid IPs and prevents header-spoofed loopback addresses.
     */
    public function resolveClientIp(): string
    {
        $rawRemoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $remoteAddr = $this->sanitizeIp($rawRemoteAddr);

        // If REMOTE_ADDR is missing or invalid, treat client as invalid/unknown, NEVER 127.0.0.1
        if ($remoteAddr === '') {
            return '0.0.0.0';
        }

        // Strict: If no trusted proxies configured, or REMOTE_ADDR is not trusted, ONLY use REMOTE_ADDR
        if (empty($this->trustedProxies) || !IpMatcher::match($remoteAddr, $this->trustedProxies)) {
            return $remoteAddr;
        }

        $request = Yii::$app !== null ? Yii::$app->request : null;
        if ($request === null) {
            return $remoteAddr;
        }

        foreach ($this->ipHeaders as $header) {
            $val = $request->headers->get($header);
            if ($val !== null && trim($val) !== '') {
                $ips = array_map('trim', explode(',', $val));
                // Walk from right to left to find the first untrusted client IP
                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    $cand = $this->sanitizeIp($ips[$i]);
                    // Skip invalid IPs in the chain; NEVER convert them to loopback!
                    if ($cand === '') {
                        continue;
                    }
                    // A client cannot claim loopback/localhost via a proxy header
                    if (self::isLoopbackIp($cand)) {
                        continue;
                    }
                    if (!IpMatcher::match($cand, $this->trustedProxies)) {
                        return $cand;
                    }
                }
                // If all in chain were trusted proxies, check leftmost valid candidate
                $leftmost = $this->sanitizeIp($ips[0]);
                if ($leftmost !== '' && !self::isLoopbackIp($leftmost)) {
                    return $leftmost;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Sanitizes and validates an IP address string.
     * Returns an empty string if the IP is invalid. NEVER defaults to 127.0.0.1.
     */
    public function sanitizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '';
    }

    /**
     * Checks if an IP is a loopback address (IPv4 127.0.0.0/8 or IPv6 ::1).
     */
    public static function isLoopbackIp(string $ip): bool
    {
        $ip = trim($ip);
        return $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.');
    }

    /**
     * Resolves the encryption key used for signing cookies, PoW and tokens.
     * Prioritizes configured keys and environment variables over auto-generated random keys.
     * Eliminates predictable path-based fallbacks.
     */
    public function getResolvedSecretKey(): string
    {
        if (!empty($this->secretKey)) {
            return $this->secretKey;
        }

        $envSecret = getenv('ANTIBOT_SECRET_KEY');
        if ($envSecret !== false && trim($envSecret) !== '') {
            return trim($envSecret);
        }
        if (!empty($_ENV['ANTIBOT_SECRET_KEY'])) {
            return trim((string) $_ENV['ANTIBOT_SECRET_KEY']);
        }
        if (!empty($_SERVER['ANTIBOT_SECRET_KEY'])) {
            return trim((string) $_SERVER['ANTIBOT_SECRET_KEY']);
        }

        if (Yii::$app !== null && !empty(Yii::$app->request->cookieValidationKey) && strlen(Yii::$app->request->cookieValidationKey) >= 16) {
            return Yii::$app->request->cookieValidationKey;
        }

        // Cryptographically secure persistent auto-secret (never predictable from directory path!)
        $this->_hasSecretKeyWarning = true;
        return $this->_storage->getOrCreateAutoSecret();
    }

    /**
     * Checks whether the module is currently operating in Monitoring mode.
     */
    public function isMonitoringMode(): bool
    {
        return $this->mode === StorageService::MODE_MONITORING;
    }

    public function hasSecretKeyWarning(): bool
    {
        return $this->_hasSecretKeyWarning;
    }

    /**
     * Initializes request inspection timing and state tracking.
     */
    public function startInspection(): void
    {
        $this->_inspectionStartHr = function_exists('hrtime') ? hrtime(true) : (int) (microtime(true) * 1e9);
        $this->_currentDecision = 'allowed';
        $this->_inspectionRecorded = false;
    }

    /**
     * Completes request inspection and records high-precision performance metrics.
     * Guaranteed to execute at most once per request across all normal and early termination paths.
     */
    public function finishInspection(string $decision, bool $completedCleanly = true): void
    {
        if ($this->_inspectionRecorded) {
            return;
        }
        $this->_inspectionRecorded = true;
        $this->_currentDecision = $decision;

        $endHr = function_exists('hrtime') ? hrtime(true) : (int) (microtime(true) * 1e9);
        $startHr = $this->_inspectionStartHr ?? $endHr;
        $durationMs = max(0.001, ($endHr - $startHr) / 1e6);
        $this->recordInspectionMetric($durationMs, $decision, $completedCleanly);
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

        $this->startInspection();

        try {
            $ip = $this->resolveClientIp();
            $ua = $request->getUserAgent() ?? '';
            $url = $request->getAbsoluteUrl();

            // If client IP is invalid or 0.0.0.0, deny access (never whitelist)
            if ($ip === '0.0.0.0' || $ip === '') {
                $this->_currentDecision = 'blocked';
                $this->blockRequest('Malformed or unresolvable client IP address', $ip, $ua, $url);
                return;
            }

            // 1. Check JS Challenge verification submission
            if ($request->isPost && $request->post('antibot_challenge_verify') === '1') {
                $this->_currentDecision = 'challenge';
                $this->handleChallengeSubmission($ip, $ua);
                return;
            }

            // 1b. Check Proof-of-Work Challenge submission
            if ($request->isPost && $request->post('antibot_pow_solve') === '1') {
                $this->_currentDecision = 'challenge';
                $this->handlePowSubmission($ip, $ua);
                return;
            }

            // 1c. Check Honey URL Trap access
            if ($this->honeyTrapsEnabled && ($pathInfo === $this->trapRoute || $pathInfo === ltrim($this->trapRoute, '/'))) {
                $this->_currentDecision = 'blocked';
                $this->handleTrapHit($ip, $ua);
                return;
            }

            // 2. Whitelist Check
            if (IpMatcher::match($ip, $this->whitelist)) {
                // Strict loopback verification: if whitelisted IP is loopback, direct connection must also be loopback
                if (self::isLoopbackIp($ip)) {
                    $rawRemote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                    if (!self::isLoopbackIp($rawRemote)) {
                        $this->_currentDecision = 'blocked';
                        $this->blockRequest('Spoofed localhost loopback via header', $ip, $ua, $url);
                        return;
                    }
                }
                $this->_currentDecision = 'allowed';
                return;
            }

            // 3. Blacklist Check
            if (IpMatcher::match($ip, $this->blacklist)) {
                $this->_currentDecision = 'blocked';
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
                        $this->_currentDecision = 'allowed';
                        return; // Pass verified search engine through
                    }

                    if ($dnsStatus === ReverseDnsVerifier::STATUS_FAKE && $this->blockFakeSearchBots) {
                        $this->_currentDecision = 'blocked';
                        $this->blockRequest('Fake search bot spoofing ' . ucfirst($searchEngine), $ip, $ua, $url);
                        return;
                    }
                    // STATUS_UNKNOWN: do NOT block as fake; proceed with standard checks
                } else {
                    $this->_currentDecision = 'allowed';
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
                    $this->_currentDecision = 'blocked';
                    $this->blockRequest('AI crawler blocked: ' . $botName, $ip, $ua, $url);
                    return;
                } elseif ($policy === 'monitor') {
                    $this->_currentDecision = 'monitored';
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
                $this->_currentDecision = 'blocked';
                $this->blockRequest('Commercial scraper blocked: ' . $scraper, $ip, $ua, $url);
                return;
            }

            // 7. CLI tools & automated HTTP libraries
            $cliTool = BotDetector::detectCliAndLibraries($ua);
            if ($cliTool !== null && $this->blockCliAndLibraries) {
                $this->_currentDecision = 'blocked';
                $this->blockRequest('Automated CLI or HTTP library: ' . $cliTool, $ip, $ua, $url);
                return;
            }

            // 8. Anomalous HTTP headers
            $anomReason = BotDetector::detectAnomalousHeaders($request->headers->toArray());
            if ($anomReason !== null && $this->blockCliAndLibraries) {
                $this->_currentDecision = 'blocked';
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
                    $this->_currentDecision = 'throttled';
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
                    $this->_currentDecision = 'throttled';
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
                        if ($this->isMonitoringMode()) {
                            $this->_currentDecision = 'monitored';
                            $this->_storage->logEvent([
                                'ip' => $ip,
                                'userAgent' => $ua,
                                'action' => 'monitored',
                                'reason' => 'High risk score (' . $risk['score'] . ') [monitored]: ' . implode(', ', $behavior['signals']),
                                'url' => $url,
                            ]);
                        } elseif ($this->powEnabled && !$this->isMachineOrApiRequest()) {
                            $this->_currentDecision = 'challenge';
                            $pow = \larikmc\antibot\services\pow\PowService::generateChallenge($ip, $secretKey, $this->powDifficulty);
                            $this->_storage->logEvent([
                                'ip' => $ip,
                                'userAgent' => $ua,
                                'action' => 'challenged',
                                'reason' => 'Proof-of-Work triggered by high risk score (' . $risk['score'] . ')',
                                'url' => $url,
                            ]);
                            $this->finishInspection('challenge');
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
                                $this->_currentDecision = 'challenge';
                                $this->finishInspection('challenge');
                                $this->renderChallengeScreen($ip, $ua, $secretKey);
                                exit(0);
                            }
                        } else {
                            $this->_currentDecision = 'monitored';
                            $this->_storage->logEvent([
                                'ip' => $ip,
                                'userAgent' => $ua,
                                'action' => 'monitored',
                                'reason' => 'Behavior Anomaly: ' . implode(', ', $behavior['signals']) . ' (Risk: ' . $risk['score'] . ')',
                                'url' => $url,
                            ]);
                        }
                    } elseif ($risk['level'] === 'medium') {
                        $this->_currentDecision = 'monitored';
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

            // 13. Strict Mode Challenge (disabled in Monitoring mode)
            if ($this->mode === StorageService::MODE_STRICT && !$isVerifiedVisitor && $this->jsChallengeEnabled && !$this->isMonitoringMode()) {
                if (!$this->isMachineOrApiRequest()) {
                    $this->_currentDecision = 'challenge';
                    $this->finishInspection('challenge');
                    $this->renderChallengeScreen($ip, $ua, $secretKey);
                    exit(0);
                }
            }

            $this->_currentDecision = 'allowed';
            Yii::$app->params['__antibot_checked_ip'] = $ip;
        } catch (\Throwable $e) {
            $this->finishInspection('exception', false);
            throw $e;
        } finally {
            $this->finishInspection($this->_currentDecision);
        }
    }

    /**
     * Resolves the full path to the shared metrics file.
     */
    public static function resolveMetricsFile(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir . '/antibot_metrics.json';
        }

        if (Yii::$app !== null && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return $dir . '/antibot_metrics.json';
        }

        return sys_get_temp_dir() . '/antibot_metrics.json';
    }

    /**
     * Records an inspection metric atomically into the shared metrics storage.
     * Uses a fast non-blocking lock with bounded retries (max 15ms total wait)
     * ensuring zero count loss across independent concurrent PHP-FPM processes
     * without relying on FileCache or slow database I/O.
     */
    private function recordInspectionMetric(float $durationMs, string $decision, bool $completedCleanly = true): void
    {
        $metricsFile = self::resolveMetricsFile();
        $dir = dirname($metricsFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = @fopen($metricsFile, 'c+');
        if (!$fp) {
            return;
        }

        $locked = false;
        for ($retry = 0; $retry < 15; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(1000); // 1ms
        }

        if (!$locked) {
            @fclose($fp);
            self::$_metricDroppedRecords++;
            return; // Fail open safely without stalling the request
        }

        try {
            $raw = @stream_get_contents($fp);
            $data = [];
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            if (empty($data)) {
                $data = [
                    'recording_mode' => self::METRIC_RECORDING_MODE,
                    'sample_strategy_version' => self::SAMPLE_STRATEGY_VERSION,
                    'total_inspected' => 0,
                    'allowed' => 0,
                    'blocked' => 0,
                    'throttled' => 0,
                    'challenge' => 0,
                    'monitored' => 0,
                    'exceptions' => 0,
                    'dropped_records' => 0,
                    'duration_sum' => 0.0,
                    'duration_count' => 0,
                    'sample_capacity' => self::METRIC_SAMPLE_RESERVOIR_CAPACITY,
                    'sample_seen_count' => 0,
                    'samples' => [],
                    'updated_at' => time(),
                ];
            }

            // Migration from v1 (pre-Algorithm R) to v2
            $currentStrategyVersion = (int) ($data['sample_strategy_version'] ?? 1);
            if ($currentStrategyVersion < self::SAMPLE_STRATEGY_VERSION) {
                // Reset legacy biased sample and observation counter, while strictly preserving
                // all historical totals (total_inspected, allowed, blocked, duration_sum, etc.)
                $data['sample_strategy_version'] = self::SAMPLE_STRATEGY_VERSION;
                $data['samples'] = [];
                $data['sample_seen_count'] = 0;
            }

            if (self::$_metricDroppedRecords > 0) {
                $data['dropped_records'] = ((int) ($data['dropped_records'] ?? 0)) + self::$_metricDroppedRecords;
                self::$_metricDroppedRecords = 0;
            }
            $data['recording_mode'] = self::METRIC_RECORDING_MODE;
            $data['sample_capacity'] = self::METRIC_SAMPLE_RESERVOIR_CAPACITY;

            $data['total_inspected'] = ((int) ($data['total_inspected'] ?? 0)) + 1;
            if (isset($data[$decision])) {
                $data[$decision]++;
            } elseif ($decision === 'exception') {
                $data['exceptions'] = ((int) ($data['exceptions'] ?? 0)) + 1;
            }

            if ($completedCleanly && $decision !== 'exception') {
                $data['duration_sum'] = ((float) ($data['duration_sum'] ?? 0.0)) + $durationMs;
                $data['duration_count'] = ((int) ($data['duration_count'] ?? 0)) + 1;

                // Reservoir Sampling: Algorithm R
                $data['sample_seen_count'] = ((int) ($data['sample_seen_count'] ?? 0)) + 1;
                $n = $data['sample_seen_count'];
                $capacity = self::METRIC_SAMPLE_RESERVOIR_CAPACITY;

                if (!isset($data['samples']) || !is_array($data['samples'])) {
                    $data['samples'] = [];
                }

                $roundedDuration = round($durationMs, 4);

                if (count($data['samples']) < $capacity) {
                    $data['samples'][] = $roundedDuration;
                } else {
                    $j = random_int(1, $n);
                    if ($j <= $capacity) {
                        $data['samples'][$j - 1] = $roundedDuration;
                    }
                }
            }
            $data['updated_at'] = time();

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * Backward-compatible flush method (metrics are committed atomically in real-time).
     */
    public function flushMetrics(): void
    {
        // No-op: Metrics are persisted in real-time to avoid request termination loss.
    }

    /**
     * Resets metrics storage (for testing and audit baselines).
     */
    public static function resetMetrics(): void
    {
        $file = self::resolveMetricsFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $empty = [
            'recording_mode' => self::METRIC_RECORDING_MODE,
            'sample_strategy_version' => self::SAMPLE_STRATEGY_VERSION,
            'total_checks' => 0,
            'total_inspected' => 0,
            'allowed' => 0,
            'blocked' => 0,
            'throttled' => 0,
            'challenge' => 0,
            'monitored' => 0,
            'exceptions' => 0,
            'dropped_records' => 0,
            'duration_sum' => 0.0,
            'duration_count' => 0,
            'sample_capacity' => self::METRIC_SAMPLE_RESERVOIR_CAPACITY,
            'sample_seen_count' => 0,
            'samples' => [],
            'updated_at' => time(),
        ];
        self::$_metricDroppedRecords = 0;
        @file_put_contents($file, json_encode($empty, JSON_PRETTY_PRINT));
    }

    /**
     * Returns comprehensive inspection performance metrics and telemetry.
     *
     * @return array{
     *     avg_ms: ?float,
     *     p50_ms: ?float,
     *     p95_ms: ?float,
     *     total_checks: int,
     *     total_inspected: int,
     *     allowed: int,
     *     blocked: int,
     *     throttled: int,
     *     challenge: int,
     *     monitored: int,
     *     exceptions: int,
     *     dropped_records: int,
     *     dropped_records_status: string,
     *     recording_mode: string,
     *     sample_size: int,
     *     sample_capacity: int,
     *     sample_seen_count: int,
     *     sample_strategy: string,
     *     sample_strategy_version: int
     * }
     */
    public function getInspectionMetrics(): array
    {
        $default = [
            'avg_ms' => null,
            'p50_ms' => null,
            'p95_ms' => null,
            'total_checks' => 0,
            'total_inspected' => 0,
            'allowed' => 0,
            'blocked' => 0,
            'throttled' => 0,
            'challenge' => 0,
            'monitored' => 0,
            'exceptions' => 0,
            'dropped_records' => 0,
            'dropped_records_status' => 'exact_count_unknown',
            'recording_mode' => self::METRIC_RECORDING_MODE,
            'sample_size' => 0,
            'sample_capacity' => self::METRIC_SAMPLE_RESERVOIR_CAPACITY,
            'sample_seen_count' => 0,
            'sample_strategy' => 'Reservoir Sampling Algorithm R (Capacity=' . self::METRIC_SAMPLE_RESERVOIR_CAPACITY . ')',
            'sample_strategy_version' => self::SAMPLE_STRATEGY_VERSION,
        ];

        $metricsFile = self::resolveMetricsFile();
        if (!file_exists($metricsFile)) {
            return $default;
        }

        try {
            $raw = @file_get_contents($metricsFile);
            if ($raw === false || trim($raw) === '') {
                return $default;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $default;
            }

            $strategyVersion = (int) ($data['sample_strategy_version'] ?? 1);
            $samples = is_array($data['samples'] ?? null) ? $data['samples'] : [];
            $sampleSeen = (int) ($data['sample_seen_count'] ?? 0);

            // Backward compatibility / migration for legacy samples
            if ($strategyVersion < self::SAMPLE_STRATEGY_VERSION) {
                $samples = [];
                $sampleSeen = 0;
            }

            $total = (int) ($data['total_inspected'] ?? 0);
            $count = count($samples);
            $p50 = null;
            $p95 = null;

            if ($count > 0) {
                $sorted = $samples;
                sort($sorted, SORT_NUMERIC);
                $p50Idx = (int) floor($count * 0.50);
                $p95Idx = min($count - 1, (int) floor($count * 0.95));
                $p50 = (float) $sorted[$p50Idx];
                $p95 = (float) $sorted[$p95Idx];
            }

            $durCount = (int) ($data['duration_count'] ?? 0);
            $durSum = (float) ($data['duration_sum'] ?? 0.0);
            $avg = $durCount > 0 ? round($durSum / $durCount, 3) : null;

            return [
                'avg_ms' => $avg,
                'p50_ms' => $p50,
                'p95_ms' => $p95,
                'total_checks' => $total,
                'total_inspected' => $total,
                'allowed' => (int) ($data['allowed'] ?? 0),
                'blocked' => (int) ($data['blocked'] ?? 0),
                'throttled' => (int) ($data['throttled'] ?? 0),
                'challenge' => (int) ($data['challenge'] ?? 0),
                'monitored' => (int) ($data['monitored'] ?? 0),
                'exceptions' => (int) ($data['exceptions'] ?? 0),
                'dropped_records' => (int) ($data['dropped_records'] ?? 0),
                'dropped_records_status' => 'exact_count_unknown',
                'recording_mode' => (string) ($data['recording_mode'] ?? self::METRIC_RECORDING_MODE),
                'sample_size' => $count,
                'sample_capacity' => self::METRIC_SAMPLE_RESERVOIR_CAPACITY,
                'sample_seen_count' => $sampleSeen,
                'sample_strategy' => 'Reservoir Sampling Algorithm R (Capacity=' . self::METRIC_SAMPLE_RESERVOIR_CAPACITY . ')',
                'sample_strategy_version' => self::SAMPLE_STRATEGY_VERSION,
            ];
        } catch (\Throwable) {
            return $default;
        }
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
            if ($this->isMonitoringMode()) {
                $this->_storage->logEvent([
                    'ip' => $ip,
                    'userAgent' => $request->getUserAgent() ?? '',
                    'action' => 'monitored',
                    'reason' => sprintf('Action rate limit exceeded on "%s" [monitored]', $route),
                    'url' => $request->getAbsoluteUrl(),
                ]);
                return; // Do NOT set $event->isValid = false in monitoring mode!
            }

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
            $this->finishInspection('blocked');
            http_response_code(403);
            exit(0);
        }

        $this->finishInspection('blocked');
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

        $this->finishInspection('throttled');
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

            $this->finishInspection('challenge');
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

            $this->finishInspection('challenge');
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

        $this->finishInspection('blocked');
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
        $this->finishInspection('challenge');
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
