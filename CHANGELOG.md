# Changelog

All notable changes to `larikmc/yii2-antibot` will be documented in this file.

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
