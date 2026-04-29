# Juslintek Cache Manager

Full-stack WordPress cache management plugin with Redis object cache, Nginx FastCGI purging, OPcache management, Cloudflare monitoring, and always-on request tracing with Excimer profiler.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Redis server (PhpRedis extension)
- Nginx with FastCGI cache (optional)
- Excimer PHP extension (optional, for function-level profiling)

## Installation

### As MU-Plugin (recommended)

```bash
# Clone into mu-plugins directory
cd wp-content/mu-plugins
git clone git@github.com:juslintek/cache-manager.git juslintek-cache-manager

# Create the loader file
cat > juslintek-cache-manager.php << 'EOF'
<?php
require_once __DIR__ . '/juslintek-cache-manager/juslintek-cache-manager.php';
EOF
```

### As Regular Plugin

```bash
cd wp-content/plugins
git clone git@github.com:juslintek/cache-manager.git juslintek-cache-manager
```

Then activate via WP Admin → Plugins.

## Features

### Cache Management
- **Redis Object Cache** — drop-in generator with full WP cache API support
- **Nginx FastCGI** — automatic purge on content changes
- **OPcache** — explorer with stats, memory visualization, cached scripts browser
- **Elementor CSS** — automatic cache clear on theme/plugin updates
- **Purge Engine** — Strategy pattern, deduplication, WP-CLI support

### Monitoring
- **Cloudflare** — real-time request monitoring via SSE, country flags, user-agent tracking
- **Cache Logs** — JSONL logging with filtering, grouping, live streaming
- **Redis Explorer** — key browser, group stats, charts, preview/delete

### Request Tracing
- **Always-on** — captures every request with zero-config
- **Hierarchical spans** — WordPress lifecycle hooks as nested tree
- **DB query tracking** — full SQL + call stack + span association
- **Excimer Profiler** — function-level sampling (file:line, self/total time)
- **Live view** — SSE push, no polling
- **Explorer** — offline filtering by any field, sortable columns
- **Detail view** — request/response headers, body, cookies, user info

### Admin UI
- Built with **Tailwind CSS** + **Alpine.js**
- Real-time updates via **Server-Sent Events**
- All data served via **WP REST API** (private endpoints)
- Collapsible sections with **localStorage** state persistence

## Architecture

```
juslintek-cache-manager.php    ← Plugin entry point
autoload.php                   ← PSR-4 autoloader
src/
  Plugin.php                   ← Service container + hook orchestration
  Contracts/                   ← Interfaces (PurgeStrategy, Hookable)
  Cache/                       ← Drop-in generator + installer
  Purge/                       ← PurgeManager + 4 strategies
  Log/                         ← JSONL logger with rotation
  Tracer/                      ← Tracer + TracerConfig
  Redis/                       ← RedisFactory
  Admin/
    RestApi.php                ← REST endpoints + SSE streams
    AdminPage.php              ← Abstract base
    Page/                      ← 8 admin page classes
  CLI/
    CacheCommand.php           ← WP-CLI commands
```

## Design Principles

- **SOLID** — Single responsibility, Strategy pattern, DI
- **PSR-4** — Autoloading under `VLT\CacheManager` namespace
- **PSR-12** — Coding style with `declare(strict_types=1)`
- **KISS** — Minimal abstractions, no over-engineering
- **Performance** — Raw PHP I/O for hot paths, WP APIs for admin-only operations

## WP-CLI

```bash
wp vlt-cache status    # Show all cache statuses
wp vlt-cache purge     # Purge all caches
wp vlt-cache purge --type=nginx
wp vlt-cache stats     # Today's hit/miss statistics
```

## REST API Endpoints

All endpoints require `manage_options` capability.

| Endpoint | Description |
|----------|-------------|
| `GET /vlt-cache/v1/logs` | Cache log entries |
| `GET /vlt-cache/v1/logs/stream` | SSE live log stream |
| `GET /vlt-cache/v1/cloudflare` | Cloudflare request data |
| `GET /vlt-cache/v1/cloudflare/stream` | SSE live CF stream |
| `GET /vlt-cache/v1/redis/{action}` | Redis explorer |
| `GET /vlt-cache/v1/tracer/{action}` | Tracer data |
| `GET /vlt-cache/v1/tracer/stream` | SSE live tracer stream |

## License

GPL-2.0-or-later
