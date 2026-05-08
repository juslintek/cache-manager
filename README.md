# Juslintek Cache Manager

Full-stack WordPress cache management plugin. Handles Redis object cache, LiteSpeed/OpenLiteSpeed page cache, Nginx FastCGI cache, OPcache, Elementor CSS, image optimization, async job queues, and real-time performance monitoring.

---

## Features

### 🔴 Redis Object Cache
- Auto-detects Redis connection: panel-specific socket paths → TCP → config file parsing
- **DirectAdmin**: `/home/<user>/.redis/redis.sock` (per DA docs)
- **cPanel/Plesk/Hestia**: `/var/run/redis/redis.sock`
- Panel detection uses 4 signals: `$_SERVER` env vars → port scan (DA:2222, cPanel:2083) → filesystem → ABSPATH path pattern
- Manual override: Nustatymai → Redis ryšys (Socket or TCP mode tabs)
- Auto-detect button fills the correct mode tab
- `object-cache.php` drop-in regenerated with the configured connection

### ⚡ LiteSpeed / OpenLiteSpeed Cache
- Native `X-LiteSpeed-Cache-Control` header management — no LSCWP plugin needed
- Sends `public,max-age=N` on cacheable pages; `no-cache` for logged-in users, WooCommerce cart/checkout, session cookies
- Cache tags (`X-LiteSpeed-Tag`) for targeted purging by post ID, taxonomy, home
- Purges on post save/delete with related term archives
- **Settings** (LiteSpeed page):
  - Enable/disable cache
  - TTL (default 86400s)
  - Cache logged-in users (off by default)
  - Cache search results
  - Cache 404 pages
- LSCache detection: PHP API → HTTP headers → `ls_enabled` in config → `.so` file → cache storage dir
- Shows `enableCache` status separately from module presence
- Config file hierarchy viewer with syntax highlighting and inline editor (if writable)
- Cache explorer: file count and size from `/usr/local/lsws/cachedata/`

### 🌐 Nginx FastCGI Cache
- Cache directory browser with URL, status, size, timestamp
- Source/render preview per cached file
- Purge on post change

### 🔧 OPcache Explorer
- Memory usage, hit rate, cached scripts
- Reset button

### 📊 Redis Explorer
- Per-group key counts and sizes
- Key browser with TTL, raw/pretty value preview
- Delete individual keys or entire groups

### 🖼️ Image Optimization
- Converts JPEG/PNG → WebP + AVIF on upload (Imagick preferred, GD fallback)
- Serves via `<picture>` element: AVIF → WebP → original fallback
- Rewrites `srcset` to WebP
- Bulk optimization with `_vlt_webp_done` meta tracking
- If LSCWP active: delegates to QUIC.cloud instead
- **Settings**: enable/disable, serve-webp toggle, quality (1–100)
- Status panel: GD ✅/❌, Imagick WebP ✅/❌, Imagick AVIF ✅/❌

### 🔔 Admin Notices
- **simdjson missing**: panel-specific install instructions (cPanel/Plesk/DA/Linux)
- **Image optimization**: green success when libvips + AVIF + WebP all installed; yellow warning with missing items; auto-dismisses after 4s when all optimal
- **Conflicting image plugins**: warns if multiple optimizers active (ShortPixel, Imagify, Smush, EWWW, etc.)
- **Excimer missing**: shown on Tracer page

### 📈 Tracer
- Span-based request tracing with Excimer sampling profiler (10ms interval, ~2% overhead)
- DB query attribution to spans
- **Hook argument tracing** (opt-in): captures every WP action/filter with args, timing, caller file:line
  - Enable: Nustatymai → Hook argumentų sekimas
  - Threshold: only record hooks slower than N ms (default 1ms)
- Request/response headers, GET/POST/cookie capture
- Live SSE stream on Tracer page
- History stored to daily JSON files

### ⚙️ Async Queue & Performance
- Redis-backed job queue: `AsyncQueue::push($hook, $args, $delay)`
- PHP 8.1+ Fibers for concurrent job execution (sequential fallback)
- `AsyncQueue::parallel($jobs)` — run multiple WP actions concurrently
- WP-Cron → Redis offload (opt-in): intercepts `pre_schedule_event`
- **GC Analyzer**: detects disabled GC, circular references, memory pressure, heavy hooks, OPcache saturation
- Auto-fix button: `gc_enable()` + `gc_collect_cycles()`
- **Live cron monitor**: SSE stream showing hook name, status, timing, memory per execution
- Per-hook stats: runs, errors, average ms

### 🎯 Targeted Cache Invalidation
30+ hooks for precise purging without full flushes:

| Event | Purge scope |
|-------|-------------|
| Post save/update/trash | Post tag + related term archives + home + Redis post groups |
| Taxonomy term change | Term tag + Redis terms group |
| Comment approve/edit | Parent post only |
| Menu update | Page cache only |
| Widget update | Page cache only |
| Critical options (siteurl, permalink…) | Full purge |
| Theme switch / Customizer | Full purge |
| Plugin activate/deactivate | Full purge |
| Plugin/theme upgrade | OPcache + page cache + Elementor |
| User profile update | Author archive URL only |
| WooCommerce stock change | Product post only |
| Elementor editor save | Post + Elementor CSS |

### 🔍 Server Detection
Runs once on plugin activation, stored in `wp_options` (autoload=false). Manual re-detect button in Nustatymai.

Detects: DirectAdmin, cPanel/WHM, Plesk, HestiaCP, VestaCP, CyberPanel, ISPmanager, InterWorx, Froxlor, plain Linux.

Web server detection: `SERVER_SOFTWARE` → port scan (OLS:7080, LS:8090) → filesystem.

### ☁️ Cloudflare
- Menu item hidden when domain not behind Cloudflare (DNS IP range check)
- Shown if "Cloudflare stebėjimas" manually enabled in settings
- CF request logging, live stream, country/ray tracking

---

## Menu Structure

Menu items shown/hidden based on detected environment:

- **Suvestinė** — always shown
- **Žurnalai** — always shown
- **Cloudflare** — only if domain behind CF or CF tracking enabled
- **LiteSpeed** — only on LiteSpeed/OpenLiteSpeed servers
- **Nginx naršyklė** — only on Nginx servers
- **Apache** — only on Apache servers
- **OPcache** — always shown
- **Redis naršyklė** — always shown
- **Tracer** — always shown
- **Našumas** — always shown (async queue + GC + cron monitor)
- **Nustatymai** — always shown

---

## Settings (Nustatymai)

| Setting | Default | Description |
|---------|---------|-------------|
| Debug režimas | off | Sets `vlt_debug_cache` cookie — bypasses Redis object cache |
| Užklausų registravimas | on | Log all requests |
| Cloudflare stebėjimas | auto | Auto-off if not behind CF |
| Žurnalų saugojimas | 30 days | |
| Redis socket | auto-detected | `/home/user/.redis/redis.sock` on DA |
| Redis host/port | auto-detected | TCP fallback |
| WP-Cron → Redis | off | Offload cron to async queue |
| Hook argumentų sekimas | off | Capture WP hook args in traces |
| Hook slenkstis | 1ms | Min duration to record |
| LiteSpeed talpykla | on | `X-LiteSpeed-Cache-Control` headers |
| LiteSpeed TTL | 86400 | Seconds |
| WebP konvertavimas | off | Convert on upload |
| WebP kokybė | 82 | 1–100 |
| LiteSpeed valymas | auto | Enabled automatically on LS servers |

---

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Redis (optional but recommended): `php-redis` extension
- Excimer (optional): `pecl install excimer` — enables sampling profiler in Tracer
- Imagick with AVIF: compiled against `alt-ImageMagick` with libavif
- libvips: `php-vips` extension for best image optimization performance

## Installation

1. Upload plugin to `wp-content/plugins/`
2. Activate — server detection runs automatically
3. If Redis available: Nustatymai → Redis ryšys → "Aptikti Redis automatiškai"
4. If LiteSpeed: LiteSpeed page → enable cache, set TTL
5. Install `object-cache.php` drop-in: Nustatymai → Veiksmai → "Įdiegti object-cache.php"

## MCP Bridge

This plugin includes the `mcp-for-page-builders/v1` REST endpoints, making it compatible with the MCP for Page Builders server without a separate bridge plugin:

- `GET /mcp-for-page-builders/v1/status`
- `POST /mcp-for-page-builders/v1/write-mu-plugin`
- `POST /mcp-for-page-builders/v1/write-theme-file`
- `GET /mcp-for-page-builders/v1/read-theme-file`
- `GET/POST /mcp-for-page-builders/v1/option/{name}`
