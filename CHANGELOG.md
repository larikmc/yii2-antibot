# Changelog

All notable changes to `larikmc/yii2-antibot` will be documented in this file.

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
