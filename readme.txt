=== Juslintek Cache Manager ===
Contributors: juslintek
Tags: cache, performance, redis, opcache, object-cache
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full-stack cache management with auto-backend detection, request tracing, and WP-CLI diagnostics. No configuration required.

== Description ==

Juslintek Cache Manager automatically detects and uses the best available cache backend on your server. It supports Redis, Memcached, APCu, SQLite WAL, and file-based storage — choosing the optimal combination without manual configuration.

**Key Features:**

* **Auto-backend detection** — Detects Redis, Memcached, APCu, SQLite, LMDB and selects the best available
* **Object cache drop-in** — Generates and installs an optimized object-cache.php
* **Request tracing** — Profile every request with hook-level timing (uses Excimer when available)
* **Nginx FastCGI cache management** — Purge and monitor Nginx page cache
* **OPcache management** — Monitor hit rates and invalidate scripts
* **Cloudflare integration** — Track CF-Ray headers and cache status
* **LiteSpeed Cache support** — Native LSCACHE header management
* **File change detection** — Smart cache invalidation when theme/plugin files change
* **JSONL audit logs** — Append-only request logs with rotation
* **WP-CLI commands** — Full CLI interface for automation

**WP-CLI Commands:**

    wp gratis-cache status        # Show backends, capabilities, stats
    wp gratis-cache purge         # Purge all cache layers
    wp gratis-cache purge-url     # Purge specific URL
    wp gratis-cache debug-url     # Debug cache state for a URL
    wp gratis-cache scan-files    # Detect file changes
    wp gratis-cache history       # Show recent cache events
    wp gratis-cache bench         # Benchmark backends

**Philosophy:**

Everything is free. No "Pro" version, no locked features, no nag screens. Optional managed cloud services (backup storage, monitoring) may be offered separately to cover infrastructure costs.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/cache-manager/`
2. Activate through the Plugins menu
3. The plugin auto-detects your server capabilities — no configuration needed
4. Visit Settings → Cache Manager for the dashboard

== Frequently Asked Questions ==

= Does this require Redis or Memcached? =

No. The plugin works on any server. It auto-detects available backends and falls back to file-based or array storage. Redis/Memcached are used as optional hot cache layers when available.

= Will this conflict with other cache plugins? =

It may conflict with plugins that also install object-cache.php. Deactivate other object cache plugins before activating this one.

= Does this work on shared hosting? =

Yes. The plugin gracefully degrades on shared hosting, CloudLinux, and DirectAdmin environments. It detects available resources and adapts.

= Does the plugin make external HTTP requests? =

No. The plugin never phones home, sends analytics, or makes external requests. All functionality is local.

= What is the request tracer? =

The tracer profiles each frontend request, measuring time spent in each WordPress hook. It uses the Excimer extension when available (zero-overhead sampling) or falls back to microtime measurements.

== Changelog ==

= 3.0.0 =
* Complete architecture rewrite with interface-based backend system
* Added auto-backend detection (Redis, Memcached, APCu, SQLite, LMDB, file)
* Added WP-CLI commands: status, purge, purge-url, debug-url, scan-files, history, bench
* Added request tracing with Excimer profiler support
* Added JSONL audit logging with rotation
* Added file change detection for smart invalidation
* Added Cloudflare request tracking
* Added LiteSpeed Cache native support
* Added CloudLinux/DirectAdmin compatibility detection

= 2.0.0 =
* Added Nginx FastCGI cache management
* Added OPcache monitoring
* Added Redis object cache drop-in generator

= 1.0.0 =
* Initial release with basic Redis object cache support

== Upgrade Notice ==

= 3.0.0 =
Major rewrite. Backup your object-cache.php before upgrading. The plugin will regenerate it automatically.
