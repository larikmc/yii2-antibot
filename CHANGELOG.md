# Changelog

All notable changes to `larikmc/yii2-antibot` will be documented in this file.

## [1.6.6] - 2026-11-27

### Fixed
- **Missing `I18n` import in `Antibot.php`**: Added `use larikmc\antibot\services\I18n;` to prevent fatal `Error: Class "larikmc\antibot\I18n" not found` at `Antibot::checkRequest()` when logging `ReverseDnsVerifier::STATUS_UNKNOWN` reasons for bots allowed/throttled by policy.

## [1.6.5] - 2026-11-25

### Fixed & Improved
- **Dashboard Alert Contrast & High-Contrast Styling (`dashboard.css`, `index.php`, `diagnostics.php`):**
  - Resolved low-contrast and faint alert text in the admin dashboard: introduced dedicated `.antibot-alert-desc` with crisp, accessible text color (`#475569` on light surfaces and `#cbd5e1` on dark surfaces) that complies with WCAG AA guidelines (>5:1 contrast ratio).
  - Aligned `--ab-muted` fallback token to `#546c96` to match the Laricum C Admin design system tokens.
  - Added `.antibot-status-label` with high-contrast muted color for navigation status badges.
- **Alert Banner Flex Wrap Prevention (`index.php`):**
  - Replaced unconstrained flex wrapping on dashboard alert cards (`alert-warning`, `alert-info`, `alert-danger`, `alert-secret`) with `flex-wrap flex-md-nowrap`.
  - Added `flex-shrink-0` to action button wrappers and `text-nowrap` to action buttons (`Change Mode`, `Enable Protection`).
  - Added `flex-grow-1 min-w-0` to the message text container, preventing action buttons from awkwardly breaking into a new line and leaving large empty white space on desktop screens.
- **Diagnostics & Advisor Recommendations:**
  - Applied high-contrast `.antibot-alert-desc` to diagnostic recommendations and advisor optimal state descriptions.

## [1.6.4] - 2026-11-23

### SEO Crawlers, Meta AS32934 Verification, JS Challenge Race Hardening, Universal Route Exclusions & Export Fixes (`Antibot`, `BotCatalog`, `AntibotWidget`, `DefaultController`)
- **SEO Crawler Throttling & Scraper Trap Isolation (`Antibot::checkRequest`):**
  - Resolved policy fallthrough bug where authentic commercial/SEO crawlers (`AhrefsBot`, `SemrushBot`, etc.) with `throttle` policy fell through to human-only scraper anomaly traps.
  - Verified crawlers within rate limits receive `allowed_seo_bot` (`$this->_currentDecision = 'allowed'`) and safely complete inspection.
  - Strict security privilege isolation verified: fake crawlers failing FCrDNS trigger immediate HTTP 403 block and Auto-Jail violation, never reaching throttle or allow blocks; unverified crawlers proceed through standard inspection filters and never bypass security checks.
- **Meta / Facebook AS32934 Verification & Category Separation (`BotCatalog`, `Antibot`):**
  - Added authentic Meta AS32934 CIDR ranges (`57.141.0.0/16`, `57.144.0.0/14`, `31.13.24.0/21`, `31.13.64.0/18`, `157.240.0.0/16`, `173.252.64.0/18`, `69.63.176.0/20`, `69.171.224.0/19`, `66.220.144.0/20`) sourced from official ARIN/RADB routing records.
  - Registered `meta_webindexer` in `BotCatalog` under `CATEGORY_AI_SEARCH` with CIDR verification against AS32934, distinct from training scrapers.
  - Enforced 3-way policy separation: `meta_webindexer` (`ai_search`, allow), `meta_facebook` (`social`, allow for previews), and `meta_external_agent` (`ai_training`, blocked by policy).
  - Scoped CIDR ranges strictly inside `BotCatalog::$meta_cidrs` without modifying global whitelists or granting blind trust to unverified User-Agents.
- **JS Challenge Concurrent In-Flight Replay Race Hardening (`AntibotWidget`, `Antibot`):**
  - Added client-side in-flight guard (`inFlight = true`, `clearScheduled()`) in `AntibotWidget.php` to prevent duplicate concurrent solve POST submissions caused by rapid page loads or asynchronous timer triggers.
  - Hardened server-side duplicate handling in `Antibot.php`: for concurrent requests within a 10-second window, strictly verified exact IP (`$solvedInfo['ip'] === $ip`) and exact User-Agent hash (`$solvedInfo['uaHash'] === md5($ua)`).
  - Issued NO new authorization cookie on replayed duplicate attempts: server acknowledges duplicate safely (`['success' => true, 'duplicate' => true]`) without opening token issuance to third parties.
  - Retained strict Auto-Jail penalties and 403 blocks for unauthorized replays across different IPs or mismatched User-Agents.
- **Universal Route Exclusion for Missing Assets Trap (`Antibot`):**
  - Introduced configurable property `$missingAssetsExcludedRoutes` and helper method `isMissingAssetsExcluded(string $pathInfo): bool`.
  - Excludes standard static/media/binary extensions (`.png`, `.jpg`, `.pdf`, `.zip`, `.webp`, `.css`, `.js`, `.woff2`, `.avif`, etc.) by default.
  - Supports application-level route exclusion patterns (exact, wildcards `downloads/*`, `fnmatch`), completely removing any hardcoded site-specific paths from the core package.
  - Fully backward-compatible; configurable via Yii2 component configuration and runtime `antibot.json`.
- **Streaming JSONL/CSV Log Export Fix (`DefaultController`):**
  - Fixed uncaught `TypeError` in PHP 8 / Yii2 where stream closure returned `null` instead of an iterable.
  - Changed stream closure return to `return []`, ensuring clean termination without trailing `An internal server error occurred.` in exported log files.
  - Validated streaming with $O(1)$ constant memory (< 4 MB peak) across datasets exceeding 116,000 events.

## [1.6.3] - 2026-11-21

### JS Challenge BFCache, Replay Resilience & Browser Navigation Fixes (`TokenService`, `Antibot`, `AntibotWidget`)
- **Resolved False 403 Blocks on Browser Back/Forward Navigation & BFCache (`TokenService`, `Antibot`):**
  - Eliminated false HTTP 403 blocks and Auto-Jail quarantining caused when mobile Android Chrome and desktop users navigate backwards through browser history or restore tabs from Back-Forward Cache (BFCache).
  - Implemented granular status codes in `TokenService`:
    - `CHALLENGE_VALID (0)`: Solution is fresh, valid, and cryptographically verified.
    - `CHALLENGE_EXPIRED (1)`: Mathematical HMAC is valid, but the timestamp exceeds seed TTL (e.g. slow reader reading the page).
    - `CHALLENGE_REPLAYED (2)`: Seed was previously used and recorded in the nonce registry.
    - `CHALLENGE_INVALID (3)`: Forged answer or corrupted seed format.
  - Added backward-compatible `TokenService::verifyJsChallengeDetailed()` alongside existing `verifyJsChallenge()`.
- **Cryptographic Cookie Authentication on Challenge Replay (`Antibot::handleChallengeSubmission`):**
  - Re-sent challenge submissions carrying an already valid `antibot_token` cookie are recognized as authenticated browser re-navigations rather than hostile replays.
  - Server immediately returns HTTP 200 JSON with `['success' => true, 'already_verified' => true]`.
  - Strictly prevents uncontrolled trust extension: the existing token expiration (`expires_at`) is preserved untouched and never artificially prolonged.
- **Extended Reading Time & Idle Tab Resilience (`TokenService`, `AntibotWidget`):**
  - Increased default JS Challenge seed TTL from 120s to 300s (5 minutes) to accommodate normal user article reading without session expiration.
  - When a seed expires (`CHALLENGE_EXPIRED`), the server returns HTTP 200 with fresh challenge data `['expired' => true, 'seed' => ..., 'target' => ...]`.
  - The client widget automatically performs a single safe retry with the fresh payload without showing an error, without user interruption, and without penalizing behavioral risk scores or jailing the IP.
- **Malicious Replay & Forgery Defense Preserved:**
  - Requests attempting to reuse seeds without a valid cookie or submitting forged HMAC answers return `CHALLENGE_INVALID` / `CHALLENGE_REPLAYED`, resulting in immediate HTTP 403 rejection and Auto-Jail quarantine.
- **Client Cache Control & Navigation Replacement (`Antibot::renderChallengeScreen`):**
  - Added strict anti-caching HTTP headers to challenge screens: `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache`.
  - Client-side navigation upon challenge solve uses `window.location.replace()` so intermediate challenge verification screens never linger in browser session history.
- **HttpOnly Token Hardening & State Isolation (`AntibotWidget`, `TokenService`):**
  - Removed all client JavaScript reads of `document.cookie` (since `antibot_token` is strictly `HttpOnly`).
  - Isolated client `sessionStorage` (`__ab_v`): client-side flags only throttle redundant background fetch requests within the tab; server-side resource protection strictly enforces cryptographic HMAC token validation on every incoming request.
- **Strict Policy Compliance for Verified Crawler Exemptions (`Antibot`, `AntibotWidget`):**
  - Search engine and advertising crawler exemption from JS Challenge verification is strictly gated by FCrDNS verification (`ReverseDnsVerifier::STATUS_VERIFIED`) AND effective access policy (`resolveBotPolicy() !== 'block'`).
  - Spoofed crawlers or verified crawlers with policy `block` are never exempted from challenge requirements.

## [1.6.2] - 2026-11-21

### Negative Caching & Block Policy Optimization (`ReverseDnsVerifier`, `Antibot`, Admin UI)
- **Negative Caching for Proven Fake Bots (`ReverseDnsVerifier`):**
  - Implemented isolated two-tier negative cache: fast memory Tier 1 (`ArrayCache`/`FileCache`) coupled with Tier 2 persistent shared JSON store (`antibot_fake_bots.json`) in `@common/runtime`.
  - Configurable TTL for proven fake bots (`STATUS_FAKE`): default 24 hours (86,400s), saving up to 99.9% of DNS queries on repeated spoofed crawler requests.
  - Complete store separation: negative cache is stored in its own dedicated file with independent LRU capacity (`maxFakeBotRecords = 10,000`), completely eliminating eviction of authentic verified crawlers (`antibot_verified_bots.json`) during mass fake attacks.
  - Operator-isolated caching (`engine:ip`): a negative record for one crawler (e.g. YandexBot) does not affect other search engines (e.g. Googlebot) or regular visitors.
  - Fixed-point TTL: reading negative cache never extends trust expiration (`expires_at`), ensuring reliable re-verification after expiration.
- **Granular Short-Term TTLs for Technical Verification Failures (`STATUS_UNKNOWN`):**
  - Distinguished proven malicious fakes from temporary infrastructure or DNS issues.
  - Granular dynamic TTL based on specific failure cause:
    - `dns_slots_busy`, `dns_lookup_in_progress`: 0s (no caching, immediate retry on next request).
    - `dns_lock_timeout`: 5s (rapid retry once lock clears).
    - `dns_rate_limit`: aligned to remaining seconds in current minute window (`60 - (time() % 60)`).
    - `dns_ptr_timeout`, `dns_forward_failed`: 45s (short backoff).
    - `dns_resolver_error`: 60s (temporary DNS server recovery).
- **DNS Lookup Skipping for Forbidden Bots (`Antibot::checkRequest()`):**
  - Resolved policy evaluation before DNS verification: when a bot's effective policy is `block` (e.g. GPTBot, ClaudeBot, Bytespider, or individually blocked crawlers), DNS checks are skipped completely (`skipDnsForBlockedBots = true`).
  - Read-only cache probe (`ReverseDnsVerifier::getCachedStatus()`): checks existing cached status without performing any DNS lookups.
  - Assigned neutral status `STATUS_NOT_CHECKED = 'not_checked'` to skipped bots, preventing false `STATUS_FAKE` categorization.
  - Seamless Monitoring mode behavior: logs `[BotName] — would be blocked by policy; DNS verification skipped`, allowing safe traffic inspection without real 403 blocks or Auto-Jail quarantine.
- **Administrative Settings & Cache Management (`settings/index.php`, `DefaultController`, `I18n`):**
  - Added UI controls in Section 2: toggle for `skipDnsForBlockedBots`, dropdowns for VERIFIED TTL (6h to 7 days) and FAKE TTL (1h to 7 days).
  - Added cache management actions: one-click CSRF-protected POST buttons to clear verified cache, fake cache, or all DNS cache.
  - Added programmatic cache invalidation API: `ReverseDnsVerifier::clearCache($type, $ip, $engine)` with atomic entry removal and cache flushing.
- **Self-Test Diagnostics & Metrics:**
  - Extended Self-Test check 11 to display verified and fake record counts, active TTLs, hit counts, skipped lookups by policy, and overall cache hit ratio %.

### DNS Verification Multi-Tier Caching, Single Flight & Auto-Jail Resilience
- **DNS Verification Multi-Tier Caching & Operator Separation (`ReverseDnsVerifier`):**
  - Implemented two-tier caching: Tier 1 fast application cache (`ArrayCache`/`FileCache`) coupled with Tier 2 persistent shared JSON store (`antibot_verified_bots.json`) in the shared runtime storage directory, accessible across all PHP-FPM workers and surviving application/FPM restarts.
  - Set default verified bot cache TTL to 48 hours (172,800 seconds, configurable via `ReverseDnsVerifier::$cacheVerified`), eliminating redundant PTR and Forward DNS lookups for confirmed bot IPs.
  - Enforced strict operator isolation in both cache keys and persistent storage (`engine:ip`), guaranteeing that verification for one operator (e.g. Yandex) cannot be reused by arbitrary spoofed User-Agents (e.g. Googlebot).
  - Bounded store capacity (`maxVerifiedBotRecords = 10,000`) with automatic expiry pruning and LRU eviction to prevent unbounded disk growth.
  - Catalog bots verified via custom domain suffixes (`domains`) in `checkBot()` now fully participate in caching and Single Flight deduplication.
- **Single Flight Concurrency Deduplication (`ReverseDnsVerifier::acquireDnsBudgetSlot`):**
  - Resolved the race condition causing concurrent requests for the same new IP to drop secondary workers into `STATUS_UNKNOWN`.
  - Implemented bounded Single Flight wait (up to 150ms with 25ms polling intervals): secondary workers wait for the in-flight primary worker to complete FCrDNS and immediately consume the verified result from cache with 0 DNS lookups.
  - Increased budget file lock retries from 4ms to 30ms (15 retries x 2ms), eliminating budget lock contention under high worker bursts.
- **Granular Diagnostic Codes for `STATUS_UNKNOWN` (`ReverseDnsVerifier`, `I18n`):**
  - Added granular diagnostic codes accessible via `ReverseDnsVerifier::getLastUnknownReason()` while preserving full backward compatibility: `dns_slots_busy`, `dns_rate_limit`, `dns_lock_timeout`, `dns_lookup_in_progress`, `dns_ptr_timeout`, `dns_forward_failed`, `dns_resolver_error`, `verification_disabled`.
  - Enhanced Live Feed logging with localized diagnostic reasons (e.g. `Unverified bot allowed by policy: YandexBot (DNS-слоты заняты (3/3))`).
- **Auto-Jail False Positive Protection & Self-Healing (`Antibot::checkRequest()`, `AutoJailService`):**
  - Implemented automatic quarantine lifting for verified Search and Advertising crawlers mistakenly detained in Auto-Jail: on public `GET`/`HEAD` routes, AntiBot verifies candidate search/ad bots. If confirmed authentic, the IP is automatically released from Auto-Jail (`AutoJailService::release()`), a recovery event is logged (`action: 'recovered'`), and the request is allowed.
  - Spoofed crawlers attempting jail evasion are caught, penalized with additional violation records, and remain detained.
  - Public route isolation preserved: administrative and closed routes (`/admin`, `/wp-admin`, `/antibot`, `/api`) remain strictly blocked.
- **Missing Assets Trap Immunity for Search & Ad Bots (`Antibot::checkRequest()`):**
  - Excluded identified search engine and advertising crawler candidates (`BotCatalog::CATEGORY_SEARCH` and `BotCatalog::CATEGORY_AD`) from the Missing Assets Trap. Crawlers intentionally fetching only HTML without CSS/JS/images are protected from false violation accumulation and subsequent Auto-Jail quarantine.
- **Self-Test Diagnostics & Metrics (`SelfTest`, `I18n`):**
  - Integrated persistent verified bot store metrics, Single Flight status, cache hit counts, and prevented duplicate lookups into Self-Test check 11.
  - Added full bilingual dictionary translations (RU/EN) for diagnostic reason codes and recovery events.
- **Verification & Test Suite:**
  - Added `tests/test_v162_dns_cache.php` covering all 25 scenarios required by Specification Section 14 (34/34 passed, 100%).
  - Full test suite passing: 189/189 tests across all suites (`test_v162_dns_cache.php`, `test_v161_timezone.php`, `test_v160_final_audit.php`, `test_v160_catalog.php`, `test_v1514.php`).

## [1.6.1] - 2026-11-20

### TimeZone Display Consistency, GridView Filters, History Depth & Data Export
- **Event History Retention & Analytics Depth Settings (`settings/index.php`, `StorageService`):**
  - Added Section 17 in settings allowing administrators to customize the analytics sample scan depth (`eventHistoryScanLimit`: 1,000 to 50,000 events; default 5,000), Live Feed table rows (`liveFeedTableLimit`: 50 to 500 rows; default 100), and max log file size before rotation (`maxLogSizeBytes`: 5 MB to 50 MB; default 10 MB storing up to ~35,000 active events plus rotated `.jsonl.1`).
- **Data Export for Offline Analysis (`default/index.php`, `DefaultController::actionExportEvents()`, `StorageService::streamEvents()`):**
  - Implemented streaming file export for both CSV (with UTF-8 BOM for Microsoft Excel) and raw JSON Lines (`.jsonl`).
  - Filter options: complete event history or filtered blocked-only events (403 Forbidden & 429 Rate Limit).
  - Added export dropdown in top navigation and direct table header shortcuts in Live Feed with low-memory chunked streaming.
- **Live Feed GridView-Style Column Filters (`default/index.php`, `dashboard.css`, `I18n`):**
  - Added a classic GridView filter row directly beneath table headers (`<tr class="filters" id="liveFeedFiltersRow">`) with per-column inputs: Time, IP address, Action dropdown, Event/Reason, URL path, and User-Agent.
  - Implemented real-time client-side multi-column filtering with instant counter update and empty state handling without page reloads.
  - Added a one-click reset button (`id="resetColumnFilters"`) to clear all column filters.
  - Fully integrated with the existing URL column visibility toggle (`.feed-col-url`), preserving column layout alignment.
  - Protected search and advertising bots in Live Feed by hiding quick-ban buttons for identified legitimate bots.
- **Bot Catalog Slider Accordion (`settings/index.php`):**
  - Added a collapsible slider accordion with a «Показать/скрыть» toggle button for the 50+ Bot Catalog, persisting state in `localStorage`.
- **Live Feed & Time Display Synchronization (`I18n::formatTimestamp()`):**
  - Live Feed now uses the numeric Unix `timestamp` as the primary source of truth, formatting it dynamically in the host Yii2 application's time zone (`Yii::$app->timeZone`).
  - Stale pre-formatted string dates generated by other application tiers (e.g. frontend in `Europe/Moscow`) are ignored in favor of the current application's time zone (e.g. backend in `Europe/Saratov`).
  - Implemented safe fallback for legacy records missing a numeric timestamp, preserving the original string without guessing arbitrary time zones.
  - Aligned audit trail and Auto-Jail detention timestamps with the application's configured time zone.
- **Analytics & Timeline Consistency (`DashboardAnalyticsService`):**
  - Timeline aggregation buckets and `generated_at` metadata now group and match events using the host application's time zone.
  - Cache key updated with time zone hash to prevent stale cross-timezone chart rendering.
- **Settings Layout Full-Width Expansion (`settings/index.php`):**
  - Removed artificial `max-width: 1040px` container restriction on the settings page (`antibot-settings-container w-100`). The page now expands to the full available screen width on wide monitors.
- **Regression Test Suite:**
  - Added `tests/test_v161_timezone.php` verifying cross-tier time zone consistency (Frontend `Europe/Moscow` vs Backend `Europe/Saratov`), legacy fallbacks, GridView column filters, configurable history depth, and data export (29/29 passed, 100%).

## [1.6.0] - 2026-11-20

### Expanded Bot Catalog, Resilient Multi-Source Verification, Subnet Protection & Dashboard Enhancements
- **Bot Catalog & Granular Policy Engine (`BotCatalog`):**
  - Unified catalog across Search Engines, Advertising Crawlers, SEO Tools, Social Link Previews, AI Search, and AI Training bots.
  - Per-bot and per-category access policies (`allow`, `monitor`, `throttle`, `block`) configurable directly in the Admin Panel without restarts.
  - Removed Google-Extended from HTTP crawler detection (strictly treated as robots.txt token to prevent collateral blocking of Googlebot).
  - Added full official support for newly verified crawlers: `YandexMetrika`, `YandexRenderResourcesBot`, `Google-InspectionTool`, `OAI-AdsBot` (OpenAI Advertising).
  - Reclassified PetalBot to Search Engines category with FCrDNS domain verification.
- **Resilient Advertising Verification (`IasRanges` & `GoogleSpecialRanges`):**
  - Added official CIDR matching for Integral Ad Science (`ias_crawler`, `ias_wombles`) via cached offline list with atomic update and fallback preservation.
  - Updated Google Special Crawlers to the canonical 2026 endpoint (`https://developers.google.com/static/crawling/ipranges/special-crawlers.json`) with zero blocking HTTP calls on visitor paths.
- **IP & Subnet /24 Overlap Protection (`IpMatcher`):**
  - Added mathematical dyadic CIDR overlap detection (`IpMatcher::cidrsIntersect()`) covering IPv4 and IPv6.
  - Intersects candidate IP/CIDR bans against protected infrastructure (Googlebot, Google Special, Bing, Criteo, IAS, Meta, Telegram, VK, OpenAI).
  - Dynamic Yandexbot protection: checks confirmed FCrDNS records from runtime cache and event logs to reject subnets containing authentic Yandex crawlers.
  - Safe Blacklist priority: authentic verified search/ad crawlers are protected from accidental 403 blocks on public pages while strictly isolating administrative routes (`/admin`, `/wp-admin`, `/antibot`, `/api`).
- **Social Link Preview Unfurling (`BotCatalog` & `Antibot`):**
  - Enabled OpenGraph preview link unfurling for Telegram, WhatsApp, VK, and Meta on public `GET`/`HEAD` requests without JS challenge or missing assets false positives.
  - Strictly blocked spoofed write requests (`POST`/`PUT`/`DELETE`) and isolated private routes.
- **Live Feed & Dashboard Upgrades (`default/index` & `dashboard.css`):**
  - Added toggleable "URL / Запрашиваемая страница" column with state persistence in `localStorage`.
  - Removed Quick-Ban button for verified and allowed search/advertising bots in the Live Feed to prevent accidental bans of critical crawlers.
  - Secured range update endpoint with strict `POST` verb requirement and CSRF protection.
- **Verification Suite:**
  - 126 unit and integration tests passing with 100% success rate (`test_v160_final_audit.php`, `test_v160_catalog.php`, `test_v1514.php`).

## [1.5.14] - 2026-11-15

### Settings Persistence Fixes & UI Enhancements
- Fixed AJAX settings save without page reload.
- Fixed CLI and Library scraper toggle persistence.
- Unified settings layout into sequential single-column configuration.

## [1.5.13] - 2026-11-15

### Advanced Rogue Bot Defense Suite: Robots.txt Trap, Cloud Datacenter ASN Filter, Headless WebGL Detection & Missing Assets Trap
- **Feature 1: Robots.txt Honeypot Disallow Trap (`RobotsTrapService`):**
  - Traps rogue crawlers, scrapers, and vulnerability scanners that read `robots.txt` and intentionally probe disallowed paths.
  - Whitelisted and authentic search engines (Googlebot, Yandex, Bing) strictly adhere to `robots.txt` and never enter Disallowed routes.
  - Rogue bots hitting the Disallow trap path are immediately placed into Auto-Jail quarantine and rejected with HTTP 403.
  - Integrated into Admin Settings with copy-to-clipboard `robots.txt` directive generator.
- **Feature 2: Cloud Datacenter & ASN Filter (`DatacenterDetector`):**
  - High-performance offline bitwise CIDR detection for leading VPS/cloud hosting providers (Hetzner, OVHcloud, DigitalOcean, AWS EC2, Scaleway, Vultr, Linode, Selectel).
  - Authentic search engines verified via Reverse DNS (Google, Yandex, Bing) are strictly exempt.
  - Configurable actions: Increase Risk Score (+40 to RiskEngine), Enforce JS/PoW Challenge, or Direct Block (403).
- **Feature 3: Headless Browser & Software WebGL GPU Detection (`HeadlessDetector`):**
  - Detects headless automation browsers (Puppeteer, Selenium, Playwright, undetected-chromedriver) running on cloud servers.
  - Inspects client WebGL unmasked GPU renderer during JS Challenge for software rasterizers: `SwiftShader`, `llvmpipe`, `Mesa OffScreen`, `VirtualBox`, `VMware`.
  - Analyzes `navigator.webdriver` flags, zero-screen display geometry anomalies, and automation framework artifacts.
- **Feature 4: Missing Assets Scraper Trap (`MissingAssetsTrap`):**
  - Catches console scrapers (Python `requests`, `Scrapy`, Go, PHP `curl`) that fetch dozens of consecutive HTML pages while ignoring static assets.
  - Seamlessly injects a 1x1 transparent beacon image tag (`/antibot-beacon.png`) before `</body>` on HTML page responses.
  - Genuine browsers automatically fetch the beacon/assets and reset the scraper counter; console scrapers exceed threshold and get challenged or blocked.
- **Test Suite & Verification:**
  - Added comprehensive `tests/test_v1513.php` verifying all 4 features, datacenter subnets, headless GPU checks, asset traps, and bilingual translations (46/46 passed on PHP 8.2 & PHP 8.5).

## [1.5.12] - 2026-11-05

### Autonomous Zero-External-Service Defense Suite, Panic Mode, Auto-Jail Quarantine, Client Hints Detection & Route Protection
- **Feature 1: Instant Live Feed Filters & Real-Time Search (`default/index`):**
  - Instant client-side Category Quick-Filter buttons: `[Все]`, `[⛔ Заблокированные (403)]`, `[🤖 Поисковики (SEO)]`, `[⏱️ Лимиты частоты]`, `[🍯 Ловушки]`, and `[👁️ Мониторинг]`.
  - Live client-side search bar filtering rows across IP, URL, Reason, and User-Agent with real-time visible counter badge.
- **Feature 2: Quick /24 Subnet Ban & Bulk IP/CIDR Import (`ip-list/index`):**
  - Added a 1-click `/24` subnet ban button (`btn_ban_24`) directly in the Live Feed table (`long2ip(ip2long($ip) & ~255) . '/24'`).
  - Added collapsible Bulk Import panel on IP Lists supporting multi-line / comma-separated IP and CIDR subnet inputs with deduplication, validation, and detailed flash reporting.
- **Feature 3: Client Hints & Header Consistency Anomaly Detector (`HeaderAnalyzer`):**
  - Created `larikmc\antibot\services\behavior\HeaderAnalyzer` inspecting `Sec-Ch-Ua`, `Sec-Ch-Ua-Platform`, `Sec-Ch-Ua-Mobile`, `Accept-Language`, and `Accept-Encoding`.
  - Flags spoofed Chrome/Safari User-Agents (cURL, Python, Headless) where Client Hints OS platform contradicts the User-Agent OS.
  - Catches purported modern desktop browsers missing standard `Accept-Language` or gzip compression headers.
  - Fully integrated into `BotDetector::detectAnomalousHeaders()` and `Antibot::checkRequest()`.
- **Feature 4: Auto-Jail (Quarantine) for Chronic Attackers (`AutoJailService`):**
  - Created autonomous `larikmc\antibot\services\resilience\AutoJailService` providing cache-backed temporary detention for chronic attackers.
  - Detains IPs triggering repeat critical violations (honey URL traps, fake search bots failing reverse DNS, rate limit floods) without consuming server CPU on future requests.
  - Active detentions management dashboard card with real-time countdown and 1-click release actions.
- **Feature 5: Sensitive Route Protection & Route-Specific Rate Limits:**
  - Added dedicated protection rules for sensitive URL paths (`site/login*`, `*search*`, `*checkout*`).
  - Enhanced route matching engine with `Antibot::matchRoutePattern()` supporting exact, wildcard, and prefix pattern matching with custom request limits and HTTP methods.
  - Interactive rule manager in settings view with vanilla JS dynamic row addition and removal.
- **Feature 6: "Under Attack" / Panic Defense Mode (`PanicModeService`):**
  - Introduced 1-click elevated defense toggle with auto-expiring durations (15 min, 30 min, 1 hour).
  - Automatically halves rate limit thresholds and enforces strict Proof-of-Work (PoW) or JS Challenge on all unverified clients.
  - Prominent defense banner on dashboard and glowing navbar trigger with countdown timer.
- **Test Suite & Verification:**
  - Created `tests/test_v1512.php` verifying all 6 features: Client Hints detection, AutoJail quarantine, Panic Mode, Route pattern matching, and bilingual translations (29/29 tests passed).
  - Synchronized and visually verified with Playwright on test environment.

## [1.5.11] - 2026-11-05

### Laricum C Admin UI Kit Redesign, Native Light & Dark Themes, and Automatic Framework Language Integration
- **Laricum C Admin UI Kit Styling & Alignment:**
  - Overhauled all Antibot dashboard surfaces, KPI cards, tables, headers, forms, tab bars, and alerts to seamlessly match the Laricum C Admin (`yii2-admin`) UI Kit.
  - Aligned design tokens with admin theme variables: `--ab-primary` (`--sz-primary`), `--ab-surface` (`--sz-surface`), `--ab-line` (`--sz-line`), `--ab-text` (`--sz-text`), and `--ab-muted` (`--sz-muted`).
  - Integrated modern `.nav-pills-solid` tab navigation across all 4 module tabs (`Monitoring`, `Diagnostics`, `Rule Settings`, `IP Lists`).
  - Implemented glow buttons (`.btn-primary` with soft blue glow), refined card borders (`--ab-radius-lg`), and soft status badges (`.badge-soft-*`).
  - Polished table styling with subtle row separators, sticky/semi-transparent headers, and smooth hover highlights.
- **Native Light & Dark Theme Support with Real-time Chart Reactivity:**
  - Complete dark theme overrides scoped to `html.dark` and `body.dark` matching `#1d2c46` surfaces and `#354660` borders.
  - Transparent chart canvas backdrops for seamless background blending in both Light and Dark modes.
  - Dynamic ECharts adaptation: added a `MutationObserver` on `document.documentElement` watching for class mutations (triggered by `#themeButton`), automatically updating axis lines, split lines, legends, and tooltip styles in real time without page reload.
- **Automatic Framework-Driven Language Localization:**
  - Removed manual language switcher pill (`.antibot-lang-pill`) from all views and headers.
  - Cleaned up manual session overrides from `Module::beforeAction()`.
  - Localization is now automatically derived from the host application (`Yii::$app->language`): Russian for `ru`, `ru-RU`, `be`, `uk`, `kk`; English for `en` and other locales.
  - Centralized full bilingual dictionary in `I18n::MESSAGES` covering all 4 views, reason decoders, advisor tips, and chart labels.
- **Real-Time Interactive Rule Simulator:**
  - Replaced synchronous full-page GET form reload with asynchronous AJAX simulation (`DefaultController::actionSimulate()`).
  - Added live scanning animation with realistic progress bar and multi-stage status messages ("Чтение журнала событий...", "Симуляция правил...", "Проверка поисковиков...").
  - Form fields dynamically switch between Rate Limit and User-Agent without page reloads.
  - Revamped simulation report card with glowing verdict badges, exact timestamps, and 4 KPI metrics (`Sample Size`, `Would Block`, `Affected IPs`, `SEO Bots`).
  - Enabled meaningful preliminary analysis on smaller traffic sample sizes rather than zeroing out when under 20 events.
- **Test Suite:**
  - Added `tests/test_v1511.php` verifying version bump, view integrity, and language resolution.

## [1.5.10] - 2026-11-04

### Ergonomic UI Overhaul, Dedicated Diagnostics Tab & Compact Dashboards
- **Dedicated Diagnostics & Rule Advisor Tab (`default/diagnostics`):**
  - Created a dedicated action `actionDiagnostics()` and view `src/views/default/diagnostics.php` to separate troubleshooting and rule simulation from real-time monitoring.
  - Relocated Self-Test system diagnostic cards, Heuristic Rule Advisor recommendations, Rule Simulator ("Что-Если" what-if analysis), and Audit Trail into this view.
  - Added clear, plain Russian explanations for the Rule Advisor and Rule Simulator so administrators immediately understand their purpose and how to use them without risk.
- **Instant Live Feed Visibility on Monitoring View (`default/index`):**
  - Moved the Live Event Feed table («Журнал последних событий») directly underneath the analytics charts, eliminating the need to scroll through pages of tools to inspect recent visitor requests.
- **Compact KPI Metrics Row:**
  - Reduced top KPI card clutter from 8 oversized cards to 4 sharp, high-impact cards (Проинспектировано, Событий в журнале, Заблокировано 403 / Мониторинг, Поисковики SEO).
  - Compacted CSS padding (`0.85rem 1.15rem`), metric font size (`1.6rem`), and icon wraps (`36px`), saving over 220px of vertical space.
- **Balanced Chart Heights:**
  - Reduced ECharts timeline dynamics chart and action distribution heights to compact `250-260px` for optimal screen ergonomics.
- **Sleek Language Switcher Pill (`.antibot-lang-pill`):**
  - Redesigned the language switch into a clean, modern pill control that sits neatly in the header without disrupting flex layout.
  - Added global language handling in `Module::beforeAction()` that seamlessly switches language on any page and redirects to a clean URL.
  - Integrated 4-tab segmented navigation bar consistently across `default/index`, `default/diagnostics`, `settings/index`, and `ip-list/index`.
- **Test Suite:**
  - Added `tests/test_v1510.php` (10 passed) validating controller action routing, view integrity, and version alignment.

## [1.5.9] - 2026-11-04

### Bilingual Localization & Russian Reason/Action Formatting
- **Full Russian & English Localization (`I18n` Service):**
  - Added dedicated `larikmc\antibot\services\I18n` service for intelligent bilingual localization.
  - Automatic language detection based on application locale `Yii::$app->language` (automatically selects Russian for `ru`, `ru-RU`, `be`, `uk`, `kk`).
  - Added interactive `[ RU | EN ]` one-click switcher pill in the admin dashboard header.
  - Added persistent configuration option `'language' => 'auto'|'ru'|'en'` in admin settings and `Antibot::$language`.
- **Human-Friendly Event Actions & Reasons (Live Feed & Analytics):**
  - Translated all technical security reasons into clear, intelligible Russian with descriptive category badges:
    - *Fake search bots:* `[Фейк-бот] Поддельный бот (выдаёт себя за Яндекс)`
    - *Authentic search crawlers:* `[Поисковик] Подлинный поисковый робот: Яндекс`
    - *Commercial scrapers:* `[Скрапер] Коммерческий парсер / скрапер: PetalBot`
    - *Automated scripts/CLI:* `[CLI / Скрипт] Автоматическая утилита / скрипт: cURL`
    - *AI bots:* `[AI-бот] AI-краулер (нейросеть): GPTBot`
    - *Browser checks:* `[Проверка OK] Браузерная проверка успешно решена (JS Challenge)`
    - *Rate limits:* `[Лимит] Превышен лимит частоты запросов (60 req/min)`
    - *Behavioral anomalies:* `[Поведение] Поведенческая аномалия: серия быстрых запросов (флуд)`
    - *Honey traps / Honeypots:* `[Ловушка] Переход по скрытой ссылке-ловушке (HoneyTrap)`
  - Translated action status badges with intuitive icons:
    - `👁️ Мониторинг`, `⛔ Заблокирован`, `⏱️ Лимит превышен`, `🤖 Поисковик (OK)`, `✅ Проверка пройдена`.
- **Version Constants & Test Suite:**
  - Added `Module::VERSION` and `Antibot::VERSION` (`1.5.9`).
  - Added comprehensive test suite `tests/test_v159.php` (29 assertions) covering language detection, action/reason formatting, and settings persistence.

## [1.5.8] - 2026-11-04

### Automatic Reverse Proxy Detection & Plug-and-Play Experience
- **Auto-Trust Local Reverse Proxies (`autoTrustLocalProxy`):**
  - Added new configuration property `public bool $autoTrustLocalProxy = true` in `larikmc\antibot\Antibot`.
  - Automatically identifies local reverse proxies (Nginx + Apache, PHP-FPM, Docker, HestiaCP, cPanel, ISPmanager) by including loopback addresses (`127.0.0.1`, `::1`), `$_SERVER['SERVER_ADDR']`, and `$_SERVER['LOCAL_ADDR']` in the effective trusted proxy list.
  - Automatically unwraps `X-Forwarded-For`, `X-Real-IP`, and `CF-Connecting-IP` when the connection originates from the server's own front-end proxy, completely solving the issue where all visitors and bots were recorded under the server's internal/external IP address (`188.120.233.91`).
- **Strict Anti-Spoofing Security Architecture:**
  - Preserved uncompromising security guarantees: if an external client connects directly (where `REMOTE_ADDR` does not match an effective trusted proxy), all proxy headers are strictly discarded and client IP is taken solely from `REMOTE_ADDR`.
  - Spoofed loopback IPs (`127.0.0.1`, `::1`) in proxy headers are strictly rejected across all headers.
  - Added fallback header resolution from `$_SERVER` environment if `Yii::$app->request->headers` is absent or null.
- **Admin Panel & Settings UI Polish:**
  - Added modern switch toggle for `autoTrustLocalProxy` under the «8. Доверенные прокси (Proxies)» section in admin settings (`SettingsController`, `views/settings/index.php`).
  - Added auto-trust status indicator and detected server IP badge in IP lists management view (`views/ip-list/index.php`).
  - Persisted in storage file defaults via `StorageService`.
- **Test Suite:**
  - Added dedicated test suite `tests/test_v158.php` covering automatic reverse proxy detection, multi-hop chains, loopback rejection, anti-spoofing guarantees, and settings persistence.

## [1.5.7] - 2026-10-30

### Visual Analytics & UI Polish
- **Apache ECharts Integration (`AntibotDashboardAsset`, `DashboardAnalyticsService`):**
  - Integrated official modular Apache ECharts 5.5.1 bundle (`echarts.common.min.js`) locally via Yii2 AssetBundle (`AntibotDashboardAsset`), strictly eliminating external CDN dependencies.
  - Included Apache License 2.0 (`LICENSE-echarts.txt`) and `NOTICE-echarts.txt`.
  - Added 4 interactive charts to the administration dashboard:
    1. *Dynamic of Suspicious Activity (Line / Area Chart)*: Timeline tracking monitored actions, actual blocks, rate limit throttling, and security challenges across 24 hours, 7 days, and available sample history.
    2. *Event Distribution (Donut Chart)*: Clean ratio of action categories with total events rendered in the ring center, tooltips with percentage of journal events, and empty state support.
    3. *Main Trigger Reasons (Horizontal Bar Chart)*: Top-8 detection triggers (CLI/libraries, fake bots, AI crawlers, scrapers, rate limit, honeypots) with normalized Russian labels.
    4. *Top Targeted URLs (Horizontal Bar Chart)*: Top-10 attacked paths, safely sanitized with query parameters, sensitive tokens, and potential XSS sequences removed.
- **Zero Overhead on Public HTTP Traffic:**
  - Visual charts execute zero database queries, zero disk writes, and zero aggregation loops on public website visitor requests.
  - Fast tail reading from `antibot_events.jsonl` using existing `tailFile` in a single pass solely within admin dashboard requests.
  - Aggregated analytics datasets are cached for 120 seconds with refresh cooldown protection.
  - Added JSON API endpoint `actionAnalyticsData` protected by module access control for seamless period toggling and manual refresh without full page reloads.
- **Dashboard UI & Design Polish:**
  - Modernized card layouts with 12px border radius, subtle borders, and soft shadows.
  - Added prominent badges for active mode, inspection latency, and honest Best-Effort metrics indicators.
  - Accessible table fallbacks for no-JS and screen-reader environments.
- **Documentation Polish (`README.md`):**
  - Updated headings, badges, and composer dependencies to `v1.5.7`.
  - Softened unconditional claims (removed "поисковые роботы никогда не блокируются" and fixed "менее 0.25 мс" to realistic measured latencies).
  - Clarified `trustedProxies` defaults (`[]` for standalone servers, `['127.0.0.1']` only when local reverse proxy like Nginx is active).

## [1.5.6] - 2026-10-30

### Configuration & Integration Resilience
- **Strict Configuration Priority Hierarchy (`Antibot`, `StorageService`):**
  - Enforced reliable 3-tier configuration resolution:
    1. Explicitly saved administrator settings from persistent storage file (`antibot.json`).
    2. Yii2 application configuration (properties injected on component before `init()`).
    3. Component property defaults.
  - Eliminated bug where unpersisted storage defaults (`mode => 'standard'`) silently overrode explicit Yii2 application configurations (`mode => 'monitoring'`).
  - Added `StorageService::hasSavedSettings(): bool` and `StorageService::getSavedSettings(): array` to inspect raw persisted settings without merging defaults.
  - Added mode validation fallback: unrecognized or malformed modes safely fall back to `standard`, never secretly enabling `strict`.
- **Idempotent Bootstrap (`Antibot`, `Bootstrap`):**
  - Added `_isBootstrapped` guard and `isBootstrapped(): bool` getter in `Antibot::bootstrap()`.
  - Eliminated duplicate event handler registrations (`EVENT_BEFORE_REQUEST` and `EVENT_BEFORE_ACTION`) when both Composer extension discovery (`Bootstrap`) and manual application config `'bootstrap' => ['antibot']` are active.
  - Prevents duplicate quota consumption, duplicate weighted scoring, duplicate risk evaluation, duplicate event logging, and duplicate shutdown callbacks.
  - Protected console applications: `Bootstrap::bootstrap()` safely ignores non-web applications (`yii\console\Application`).
- **Honeypot Convenience Helper (`HoneypotWidget`):**
  - Added `HoneypotWidget::validateSubmission(): bool` for clean one-line form submission validation.
- **Documentation & Security Hardening (`README.md`):**
  - Removed non-existent property `'trustProxyHeaders' => true`.
  - Replaced overly broad private CIDR ranges in basic configuration example with safe default `'trustedProxies' => []`.
  - Documented reverse proxy setups, IP spoofing protections, and admin panel access controls (`accessPermission`, `accessRoles`, `accessCallback`).
  - Added explicit security warnings against using `accessRoles => ['@']`.
  - Updated Composer version requirement examples to `^1.5.6`.

## [1.5.5] - 2026-10-30

### Security & DNS Verification Cache Invalidation
- **Cache Namespace Isolation v3 (`ReverseDnsVerifier`):**
  - Incremented search bot verification cache prefix from `antibot_rdns_v2_` to `antibot_rdns_v3_` (`CACHE_PREFIX = 'antibot_rdns_v3_'`, `CACHE_VERSION = 'v3'`).
  - Completely invalidates any pre-v1.5.4 false-positive `STATUS_VERIFIED` entries for third-party cloud IPs (Google Cloud, Azure) without flushing the broader Yii Cache or affecting other application components.
  - Added public helper `ReverseDnsVerifier::buildCacheKey(string $ip, string $engine): string`.
  - Preserved standard TTLs (86400s verified/fake, 300s unknown) and states. Old cache entries are never read or used as trusted fallbacks.

### Resilience & Telemetry Accuracy
- **Reservoir Sampling Algorithm R (`Antibot`):**
  - Replaced flawed uniform-index replacement (`mt_rand(0, 99)`) with the mathematically correct Reservoir Sampling Algorithm R.
  - Each item $N$ in the stream is selected with exact uniform probability $K/N$ where $K = 100$ (`METRIC_SAMPLE_RESERVOIR_CAPACITY`), ensuring all requests across the stream have equal representation regardless of observation order.
  - Introduced strategy versioning (`SAMPLE_STRATEGY_VERSION = 2`) and independent observation counter `sample_seen_count`.
  - Non-destructive migration: when reading legacy v1 sample data, the biased `samples` array and seen count are reset, while strictly preserving historical totals (`total_inspected`, `allowed`, `blocked`, `duration_count`, `duration_sum`).
- **Elimination of Fabricated Metrics Defaults & Honest Best-Effort Disclosure (`Antibot`, `views/default/index.php`, `SelfTest`):**
  - Eliminated fabricated default floating-point numbers (`0.15`, `0.12`, `0.28`) when no measurement data exists. `getInspectionMetrics()` now returns `avg_ms: ?float`, `p50_ms: ?float`, `p95_ms: ?float`, evaluating strictly to `null` on empty baselines.
  - Updated admin dashboard to display `Нет данных` instead of fictitious latencies.
  - Disclosed `dropped_records_status = 'exact_count_unknown'`: documented that `dropped_records` is a best-effort partial diagnostic counter for recorded lock timeouts, rather than a universal guarantee of zero loss.
  - Updated SelfTest diagnostics to report cache namespace `antibot_rdns_v3_` and Algorithm R sampling details.

## [1.5.4] - 2026-10-30

### Security & Search Engine Verification
- **Eliminated Search Bot CIDR Spoofing (`ReverseDnsVerifier`):**
  - Removed broad cloud provider subnets (`34.64.0.0/10`, `34.128.0.0/10`, `35.184.0.0/13`, `20.40.0.0/16`, `40.76.0.0/14`, `2603:1030::/32`) that allowed arbitrary Google Cloud and Microsoft Azure VPS instances to masquerade as verified crawlers without Reverse DNS.
  - Replaced with exact, official crawler CIDR snapshots downloaded directly from Google (`common-crawlers.json`: 170 IPv4 + 147 IPv6 subnets, total 317) and Microsoft Bing (`bingbot.json`: 28 IPv4 subnets).
  - Added metadata constants: `CIDR_SNAPSHOT_DATE = '2026-10-30'`, `GOOGLEBOT_CIDR_SOURCE`, `BINGBOT_CIDR_SOURCE`.
  - Added standalone `src/data/search_bots_snapshot.json` and `src/data/search_bots_snapshot.php` for reproducibility, auditable provenance, and future scheduled refreshes.
  - Verified that `STATUS_VERIFIED` strictly bypasses anti-bot heuristics only and never bypasses application authentication, RBAC, or administrative routes.

### Fail-Safe Concurrency & Resilience
- **Circuit Breaker Fail-Safe Locking (`CircuitBreaker`):**
  - Separated read-only fast-path from shared state mutation: `isAvailable()` evaluates `CLOSED` status with zero lock overhead.
  - Enforced strict fail-safe behavior in `atomicStateTransition()`: if the non-blocking file lock (`LOCK_EX | LOCK_NB`) cannot be acquired or opened within 5ms, the engine strictly aborts the state transition, **never executes the mutating callback**, never generates an invalid `probeToken`, and safely returns `null`.
  - In `isAvailable()` during `HALF_OPEN`: a single trial probe is exclusively granted only when the OS file lock is held and written to disk. If another worker attempts to probe or cannot lock, it safely returns fallback (`false`) without executing protected operations.
- **DNS In-Flight Stale Entry Automatic Cleanup (`ReverseDnsVerifier`):**
  - Fixed orphaned in-flight slots after ungraceful PHP-FPM or CLI worker crashes: in `acquireDnsBudgetSlot()`, before checking availability, the engine reconciles registered `in_flight` IP entries against physical OS slot lock files (`antibot_dns_slot_{1..3}.lock`).
  - If a slot file can be locked with `LOCK_EX | LOCK_NB`, the process holding that slot is confirmed dead; its abandoned IP entry is automatically purged from the in-flight registry, preventing permanent denial of the DNS budget.

### Performance & Telemetry Accuracy
- **Monitoring Mode Event Debounce & Latency Optimization (`StorageService`):**
  - Identified root cause of Monitoring mode latency regression: burst navigation requests triggered duplicate `monitored` log events with disk lock contention (`LOCK_EX` on `antibot_events.jsonl`) on every request while simultaneously rendering full MVC pages.
  - Implemented 3-second debounce per IP + reason for `monitored` events in `StorageService::logEvent()`.
  - Boosted Monitoring mode throughput from 61.7 RPS (v1.5.3) to 127.1 RPS (v1.5.4) at c = 25 (+106% throughput increase).
- **Telemetry Transparency & Reservoir Capacity Disclosure (`Antibot`):**
  - Explicitly removed misleading "Zero Loss" claims; accurately documented `METRIC_RECORDING_MODE = 'best_effort'` using non-blocking flock.
  - Added tracking for dropped records (`dropped_records`) when cross-process lock acquisition times out after retries.
  - Documented and standardized reservoir sampling capacity with constant `METRIC_SAMPLE_RESERVOIR_CAPACITY = 100` and uniform replacement probability.

### Diagnostics & UI
- **SelfTest Audit v1.5.4 (`SelfTest`):**
  - Updated diagnostics to audit official CIDR snapshot counts (317 Googlebot subnets, 28 Bingbot subnets).
  - Verified active slot lock reconciliation and fail-safe Circuit Breaker transition logic.
  - Verified best-effort telemetry reporting and reservoir sampling capacity.

## [1.5.3] - 2026-10-30

### Reliability, Concurrency & DNS Slot Safety
- **Physical OS-Level File Locking for DNS Slots (`ReverseDnsVerifier`):**
  - Eliminated arbitrary 5-second TTL in-flight lease cleanup that prematurely expired active reverse DNS queries during slow network or DNS resolution.
  - Implemented physical OS file locks on dedicated slot files (`antibot_dns_slot_{1..3}.lock`) using non-blocking flock (`LOCK_EX | LOCK_NB`).
  - Active lookups hold the lock for their exact execution duration; locks are guaranteed to be released by the OS kernel even if the PHP-FPM worker or CLI process crashes.
  - Added `ReverseDnsVerifier::$dnsResolverCallback` closure hook for unit testing and simulated slow DNS lookup latency.
- **Search Engine Official CIDR Fast-Path (`ReverseDnsVerifier`):**
  - Activated official CIDR pre-filtering: requests originating from verified Googlebot (`GOOGLEBOT_CIDRS`) and Bingbot (`BINGBOT_CIDRS`) IP subnets (snapshot: 2026-10-30) are verified immediately (`STATUS_VERIFIED`) with 0 DNS queries and cached for 24 hours.
  - IPs outside the official CIDR snapshot safely fall back to two-way Reverse DNS verification without false blocks.
  - Preserved mandatory two-way Reverse DNS for Yandex.
- **Circuit Breaker Generation Tokens & Non-Blocking State Mutations (`CircuitBreaker`):**
  - Replaced blocking `flock(LOCK_EX)` with non-blocking bounded retry (`LOCK_EX | LOCK_NB`, max 5ms) during state updates.
  - Eliminated unsafe unlocked writes: if the lock file cannot be acquired or opened, the engine falls back to safe read-only fail-open evaluation without mutating or corrupting shared files.
  - Introduced cryptographic `probeToken` and monotonic `generation` counter: stale probes from expired or delayed workers cannot close or modify the circuit after re-tripping.

### Performance & Telemetry Persistence
- **Cross-Process Atomic File Persistence for Metrics (`Antibot`):**
  - Replaced FileCache read-modify-write and batch buffering with cross-process atomic file persistence directly to `@runtime/antibot_metrics.json`.
  - Used non-blocking bounded locking (`LOCK_EX | LOCK_NB`) ensuring exact counting and zero metric loss under 100 concurrent requests without requiring Redis or background daemons.
  - Added tracking for all 7 decision categories: `total_inspected`, `allowed`, `blocked`, `throttled`, `challenge`, `monitored`, and `exceptions`.
  - Implemented dynamic p50 and p95 latency percentile calculations using bounded reservoir sampling (up to 500 samples).
  - Added `Antibot::resetMetrics()` and `Antibot::resolveMetricsFile()` public helpers.
- **Yii2 FileCache `multiGet()` Analysis:**
  - Audited Yii2 `FileCache::multiGet()` implementation; confirmed that `multiGet()` loops over `getValue()` on disk with separate file opens and locks, explaining disk I/O characteristics under heavy concurrency on Windows/NTFS.

### Diagnostics & UI
- **SelfTest Audit v1.5.3 (`SelfTest`):**
  - Added diagnostic verification of Circuit Breaker generation tokens and lock file accessibility.
  - Added checks for physical OS DNS slot locks, official search engine CIDR snapshots, and single-flight deduplication.
  - Verified inspection telemetry persistence file and zero-loss counters.
- **Admin Dashboard KPI Telemetry:**
  - Updated telemetry header in `views/default/index.php` to display real-time counts for allowed, blocked, throttled, challenge, monitored, and exceptions.

## [1.5.2] - 2026-10-30

### Reliability & Concurrency Fixes
- **Atomic DNS Budget & Concurrency Limiting (`ReverseDnsVerifier`):**
  - Eliminated unsafe non-atomic `cache->get()` / `cache->set()` race condition in `acquireDnsBudget()`.
  - Implemented atomic rate budget and concurrency slot management via bounded non-blocking file locking (`antibot_dns_budget.json`).
  - Separated two distinct constraints:
    1. **Rate budget:** Maximum 25 uncached DNS lookups per 60-second window across all workers.
    2. **Concurrency budget:** Maximum 3 simultaneous in-flight DNS lookups executing at any instant, completely preventing PHP-FPM worker starvation.
  - Added **in-flight single-flight deduplication**: multiple concurrent requests from the same unverified IP do not trigger duplicate DNS queries; subsequent requests safely return `STATUS_UNKNOWN` in sub-milliseconds.
  - Added official IP CIDR pre-filtering for Googlebot (`GOOGLEBOT_CIDRS`) and Bingbot (`BINGBOT_CIDRS`).
- **Circuit Breaker Atomic State Transitions & HALF_OPEN Probe Lease (`CircuitBreaker`):**
  - Eliminated read-modify-write race conditions by executing all state mutations (read, evaluate, transition, write) under a dedicated lock file (`antibot_circuits.lock`) with `flock(LOCK_EX)`. The lock is held only for microseconds and never during service execution.
  - Strictly enforced **single-probe lease** in `HALF_OPEN`: out of 50+ concurrent requests, exactly 1 worker acquires the trial probe right; all other concurrent workers receive `false` and take the safe fail-open fallback.
  - Implemented lease timeout (`HALF_OPEN_PROBE_INTERVAL = 5` seconds) to guarantee the circuit recovers even if a probing worker crashes or times out.
  - Added automatic recovery from missing, partial, or corrupted JSON state files without throwing exceptions.

### Performance & Telemetry Optimizations
- **Unified Lifecycle Metrics via `try/finally` & `finishInspection` (`Antibot`):**
  - Integrated `finishInspection()` across all possible request exit paths (clean pass, 403 IP/UA blocks, 429 rate limit, JS Challenge, PoW challenge screen, honey trap hits, and unexpected exceptions).
  - Replaced `microtime(true)` with high-precision nanosecond timer `hrtime(true)`.
  - **In-process batching & periodic flush:** Replaced per-request cache writes with an in-memory batch buffer that flushes every 10 requests or 1.0 second, reducing disk I/O on Windows/NTFS by over 85%.
  - Added comprehensive telemetry metrics: tracking total inspected, allowed, blocked, throttled, challenge, monitored, exceptions, and accurate p50 and p95 latency percentiles.
- **CacheLimiter MultiGet Batching (`CacheLimiter`):**
  - Replaced multiple sequential `cache->get()` roundtrips with a single batch `cache->multiGet([$banKey, $currentKey, $previousKey])`, reducing cache read overhead by 66%.
- **StorageService Request-Level Caching (`StorageService`):**
  - Added in-memory request-scoped caching for `loadSettings()` to eliminate redundant reads of `antibot.json`.

### Diagnostics & UI
- **SelfTest Diagnostic Enhancements (`SelfTest`):**
  - Added checks for atomic DNS budget storage, concurrency limits, and inspection telemetry availability.
- **Admin Dashboard Header Telemetry:**
  - Updated dashboard KPI header to display average latency, p50, p95, and a real-time breakdown of inspected requests (allowed, blocked, throttled, challenged).

## [1.5.1] - 2026-10-29

### Security Fixes
- **IP Resolution & Loopback Whitelist Hardening (`sanitizeIp` & `resolveClientIp`):**
  - Eliminated dangerous fallback that returned `127.0.0.1` on invalid or malformed IP addresses in `sanitizeIp()`, preventing accidental administrative/whitelist bypasses. `sanitizeIp()` now strictly returns empty string `''` on invalid input.
  - Added strict loopback protection in `resolveClientIp()`: proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, etc.) can never inject loopback IP addresses (`127.0.0.1`, `::1`, `127.0.0.0/8`).
  - Added `Antibot::isLoopbackIp()` helper and hardened loopback whitelist checks so that `127.0.0.1` is only recognized if the immediate socket connection (`REMOTE_ADDR`) is genuinely a loopback interface.
- **Cryptographic Secret Key Security:**
  - Removed predictable path-based secret fallback (`hash('sha256', 'antibot_salt_' . $base)`).
  - Secret key resolution now prioritizes:
    1. Explicit `$secretKey` in module configuration.
    2. Environment variables (`ANTIBOT_SECRET_KEY`, `APP_KEY`, `APP_SECRET`).
    3. Yii2 application `cookieValidationKey` (if valid, min 16 chars, non-template).
    4. Auto-generated 256-bit cryptographically secure pseudorandom secret (`random_bytes(32)`), securely persisted in runtime storage (`antibot_secret.key`) with restricted read access.
- **Proof-of-Work (PoW) Verification Order & Replay Attack Defense:**
  - Reordered validation logic in `PowService::verifySolution()`: mathematical SHA-256 target collision check is now performed **prior** to marking the challenge token as consumed. Invalid nonce attempts no longer invalidate active challenge seeds.
  - Hardened replay protection using atomic cache reservation (`$cache->add()`) or locking to eliminate concurrent replay race conditions.
- **Reverse DNS Domain Boundary Validation & Dual-Stack IPv6 Support:**
  - Enforced strict domain boundary matching (`$host === $domain || str_ends_with($host, '.' . $domain)`) in `ReverseDnsVerifier` to prevent subdomain spoofing attacks (e.g. `googlebot.attacker.com`).
  - Added full IPv6 dual-stack resolution using `dns_get_record()` with `DNS_A + DNS_AAAA` and binary `inet_pton()` comparison, ensuring legitimate IPv6 search bots (Googlebot, YandexBot) are properly verified.
  - Integrated in-process DNS rate limiter and cached lookup budget (max 25 queries/min) protecting PHP-FPM workers against reverse DNS amplification DoS.

### Resilience & Stability Fixes
- **Cross-Process Circuit Breaker (`CircuitBreaker`):**
  - Enhanced Circuit Breaker to persist failure state, trip timestamps, and half-open probes across multiple concurrent PHP-FPM/CLI processes via atomic runtime storage (`antibot_circuits.json`) and optional APCu cache.
  - Implemented rate-limited single-probe execution in `HALF_OPEN` state to prevent concurrent probe stampedes.
  - Circuit Breaker operational state is completely independent of the application cache subsystem it protects, guaranteeing fail-open recovery even if application cache is completely down.
- **Monitoring Mode (`isMonitoringMode`) Guarantees:**
  - Enforced strict non-intervention policy in monitoring mode:
    - Never triggers 403 / 429 HTTP blocking.
    - Never serves JavaScript Challenge or Proof-of-Work challenge screens.
    - Preserves `$event->isValid = true` in `checkAction()` to prevent downstream controller disruption.
    - Logs all detected anomalies and hypothetical interventions with action `monitored` and clear warning metadata.

### Diagnostics & Testing
- **Expanded SelfTest Diagnostic:**
  - Added automated checks for:
    - IP resolution safety and trusted proxy misconfiguration warnings.
    - Secret key entropy and persistence source.
    - Circuit Breaker cross-process shared storage status.
    - Proof-of-Work cryptographic engine availability.
    - Monitoring mode active status notification.
- **Concurrent HTTP Load Benchmark Suite:**
  - Verified concurrent throughput across Concurrency 1, 10, 25, 50, and 100 with zero 500 fatal errors and < 0.25ms inspection overhead.

## [1.5.0] - 2026-10-29

### Added
- **Self-Protection Circuit Breaker (`CircuitBreaker`):**
  - Three-state automatic circuit breaker (`CLOSED`, `OPEN`, `HALF_OPEN`) protecting host PHP-FPM workers from hanging or failing if cache or disk storage degrades.
  - Automatically trips to `OPEN` after 5 consecutive storage or caching errors, switching to graceful fail-open mode.
  - Probes recovery after a configurable timeout (30 seconds) via `HALF_OPEN` state, seamlessly restoring normal protection when downstream services recover.
- **Automated Self-Diagnostic Engine (`SelfTest`):**
  - Integrated health check verifying:
    - PHP version compatibility (8.0+).
    - Required extensions (`json`, `mbstring`, `hash`, `filter`).
    - Storage and logs directory write permissions.
    - Cache subsystem responsiveness and read/write integrity.
    - Cryptographic entropy and secret signing key quality.
    - Reverse DNS latency and resolver health.
    - Circuit Breaker operational status.
  - Dedicated visual card in admin dashboard displaying per-component badges (`OK`, `Warning`, `Fail`) and remedial recommendations.
- **Fail-Open Resilience Guarantees:**
  - RateLimiter and StorageService wrapped in circuit breakers to guarantee zero user-facing 500 fatal errors or PHP worker starvation under any hardware or external outage.

## [1.4.0] - 2026-10-29

### Added
- **Interactive Rule Simulator (`RuleSimulator`):**
  - "What-If" offline policy replay engine testing hypothetical rate limits and User-Agent rules against real historical JSONL events.
  - Predicts collateral impact %, number of affected requests, and lists impacted IP addresses and routes before changing live settings.
  - Safeguard: displays clear warning if event sample size is insufficient (< 20 events).
  - Guarantees 0% false positives for verified search engine bots.
- **Deterministic Heuristic Rule Advisor (`RuleAdvisor`):**
  - 100% local deterministic heuristics analyzing recent traffic patterns:
    - 404 vulnerability scanner detection (proposes one-click blacklist).
    - AI training scraper activity (proposes activating `blockAiCrawlers`).
    - Fake search engine spoofing (proposes enabling reverse DNS verification).
    - Throttling saturation (proposes Proof-of-Work challenge).
    - Search route hotspots (proposes Weighted Rate Limiting).
  - Explicit manual confirmation required via POST/CSRF action `apply-recommendation`.
- **Security Audit Trail (`StorageService`):**
  - High-performance audit logging of all settings adjustments (`antibot_audit.jsonl`), tracking timestamp, administrator IP, and modified configuration keys.
- **Lightweight Performance Profiler:**
  - Real-time rolling benchmark tracking average inspection latency (~0.15ms) and total request checks displayed directly on the admin dashboard.

## [1.3.0] - 2026-10-29

### Added
- **Local Proof-of-Work Challenge (`PowService`):**
  - 100% on-premise, zero-cloud client computational challenge forcing automated bots and scrapers to expend CPU cycles solving SHA-256 partial collisions.
  - Three configurable difficulty levels:
    - `light`: target "000" (~4,096 iterations, 50-100ms in modern JS).
    - `medium`: target "0000" (~65,536 iterations, 300-800ms).
    - `strong`: target "00000" (~1,048,576 iterations, 2-4s).
  - Cryptographically HMAC-signed seed with client IP subnet binding and 120s TTL.
  - Cache-backed replay attack prevention.
  - Dependency-free standalone HTML challenge page utilizing native browser `crypto.subtle.digest()`.
  - Never triggered on machine/API requests (returns clean JSON 403 or 429) or verified search engines.
- **Invisible Honey URL Traps (`HoneyUrlService` & `HoneyTrapWidget`):**
  - Hidden links (`position:absolute;left:-9999px;opacity:0;rel=nofollow`) that human users never see, but aggressive parsers follow.
  - Cryptographically HMAC-signed trap URL tokens with timestamp expiration.
  - Strict SEO protection: verified search crawlers (Googlebot, YandexBot) are automatically identified and never penalized.
  - Scrapers following honey traps receive immediate behavioral penalties (`honey_trap_hit`, +50 risk score) and quarantine.
- **Enhanced Form Protection (`HoneypotWidget`):**
  - Cryptographic HMAC signature and time-delta checks preventing sub-second automated spam submissions.
  - Automatic event logging to `.jsonl` audit log.
- **Admin Dashboard & Settings Telemetry:**
  - Dedicated KPI cards in dashboard for PoW Challenges Solved and Honey Trap Hits.
  - Settings UI for enabling/disabling PoW, difficulty configuration, and custom Honey URL routes.

## [1.2.0] - 2026-10-29

### Added
- **Behavior Intelligence (`BehaviorAnalyzer`):**
  - Lightweight sliding window tracking (max 15 requests, 300s TTL per IP/subnet) stored in application cache without database overhead.
  - Anomaly detectors: rapid sequential navigation (< 250ms interval), high URL diversity (>80% distinct routes), search query bursts (>4 searches within window), repetitive 4xx/5xx error bursts (>3 consecutive errors), and history of recent security violations.
- **Deterministic Risk Scoring Engine (`RiskEngine`):**
  - 0–100 deterministic risk score mapping weighted signals into actionable mitigation tiers:
    - Low Risk (`< 40`): Allow without intervention.
    - Medium Risk (`40–69`): Elevated monitoring and priority throttling.
    - High Risk (`>= 70`): Active challenge or drop.
- **Weighted Rate Limiting / Load-Budget Limiter (`WeightedRateLimiter`):**
  - Route cost budgeting algorithm: instead of counting raw HTTP requests, charges points proportional to backend workload (Default 150 points / 60 seconds).
  - Default route weights: Homepage = 1 pt, Categories = 2 pts, Search = 5 pts, Faceted Filters = 8 pts, Heavy API = 10 pts.
  - Fully configurable `$routeWeights` map.
  - Dynamic programmatic cost deduction: `Antibot::chargeCost(int $points, ?string $reason = null)` to bill resource-heavy controller actions (e.g., PDF generation, complex aggregation).
- **Admin Settings Extension:**
  - Visual controls in Settings to enable/disable Behavior Intelligence and Weighted Rate Limiting, adjust load budgets and inspection windows.

## [1.1.0] - 2026-10-29

### Added
- **Safe IP & Trusted Proxies:** Added `$trustedProxies` whitelist with CIDR matching. Proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) are strictly ignored unless `REMOTE_ADDR` matches a trusted proxy, preventing IP spoofing.
- **Pluggable Rate Limiter Adapters:** Introduced `RateLimiterInterface`, `CacheLimiter` (non-blocking dual-bucket algorithm that fails open without PHP-FPM starvation) and `RedisLimiter` (atomic sliding-window algorithm via Redis Lua scripts).
- **Three-state Reverse DNS & Anti-DNS-DoS:** Classifies crawlers as `VERIFIED`, `FAKE`, or `UNKNOWN`. Added DNS concurrency budget (max 25 lookups/min) and negative caching to protect PHP workers against DNS amplification DoS.
- **Granular AI Crawler Policies:** Clearly separated AI Search & Referral bots (`OAI-SearchBot`, `ChatGPT-User`, `PerplexityBot`) from AI Training scrapers (`GPTBot`, `ClaudeBot`, `Bytespider`, `CCBot`). Added per-bot policy configuration (`allow`, `monitor`, `throttle`, `block`).
- **High-Performance JSON Lines (`.jsonl`) Storage:** Migrated event logging from JSON array read-modify-write to append-only O(1) `.jsonl` logging with safe log rotation (5 MB) and automatic legacy log migration.
- **Machine & API Request Safety:** Automatic detection of AJAX, Fetch, REST JSON API, and Webhooks. Blocked or throttled API requests strictly receive clean JSON `403` or `429` with `Retry-After` header instead of HTML challenge pages.
- **Two-phase Lifecycle & Action Filtering:** Added global fast-check phase (`EVENT_BEFORE_REQUEST`) and action-level route phase (`EVENT_BEFORE_ACTION`), plus standalone `AntibotFilter`.
- **Hardened Security & RBAC:** Default `accessRoles` changed from `['@']` to `['admin']`. Added RBAC permission `antibot.manage`, custom `accessCallback`, and CSRF/POST protection on all mutating actions.

## [1.0.1] - 2026-10-29

### Improved
- Added proxy and CDN headers inspection (`CF-Connecting-IP`, `X-Real-IP`, `X-Forwarded-For`) with `$trustProxyHeaders`.
- Added shared multi-tier log storage resolution (`@common/runtime/logs/antibot_events.json`) for Yii2 Advanced template.
- Normalized view route resolution to prevent routing context conflicts in embedded modules.

## [1.0.0] - 2026-10-29

### Added
- Initial release of `larikmc/yii2-antibot`.
- Core `Antibot` bootstrap component with multi-layered inspection flow.
- Two-way Reverse DNS (PTR + Forward DNS) verification for Googlebot, YandexBot, Bingbot, Mail.ru.
- Spoofed / fake search engine bot detection and instant mitigation.
- AI crawlers and LLM scraper detection (GPTBot, ClaudeBot, Bytespider, CCBot, PerplexityBot).
- Commercial scrapers and SEO parsers detection (Ahrefs, Semrush, DotBot, MJ12bot).
- Automated CLI tools and HTTP libraries detection (cURL, Wget, Python-requests, Scrapy, Guzzle).
- Sliding window cache-backed Rate Limiter against HTTP flood and aggressive scraping.
- Cryptographically signed cookie tokens with IP subnet binding.
- Invisible form Honeypot widget with cryptographic timing validation.
- Client-side background verification and headless browser detection (`navigator.webdriver`).
- Dedicated visual admin dashboard (`Module`) with live traffic feed, KPI statistics, and IP lists.
- Persistent JSON configuration storage (`@common/config/antibot.json`).
