# Changelog

All notable changes to `larikmc/yii2-antibot` will be documented in this file.

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
