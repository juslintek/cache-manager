<?php

declare(strict_types=1);

namespace VLT\CacheManager;

use VLT\CacheManager\Admin\AdminAjax;
use VLT\CacheManager\Admin\Page\ApachePage;
use VLT\CacheManager\Admin\Page\CloudflarePage;
use VLT\CacheManager\Admin\Page\CloudLinuxPage;
use VLT\CacheManager\Admin\Page\DashboardPage;
use VLT\CacheManager\Admin\Page\DocsPage;
use VLT\CacheManager\Admin\Page\LiteSpeedPage;
use VLT\CacheManager\Admin\Page\LogsPage;
use VLT\CacheManager\Admin\Page\NginxExplorerPage;
use VLT\CacheManager\Admin\Page\OpcacheExplorerPage;
use VLT\CacheManager\Admin\Page\PerformancePage;
use VLT\CacheManager\Admin\Page\RedisExplorerPage;
use VLT\CacheManager\Admin\Page\SettingsPage;
use VLT\CacheManager\Admin\Page\TracerPage;
use VLT\CacheManager\Cache\DropinGenerator;
use VLT\CacheManager\Cache\DropinInstaller;
use VLT\CacheManager\Log\Logger;
use VLT\CacheManager\Purge\PurgeManager;
use VLT\CacheManager\Purge\Strategy\ElementorStrategy;
use VLT\CacheManager\Purge\Strategy\LiteSpeedStrategy;
use VLT\CacheManager\Purge\Strategy\NginxStrategy;
use VLT\CacheManager\Purge\Strategy\OpcacheStrategy;
use VLT\CacheManager\Purge\Strategy\RedisStrategy;
use VLT\CacheManager\Tracer\TracerConfig;

final class Plugin
{
    private static ?self $instance = null;
    private Logger $logger;
    private PurgeManager $purge;
    private DropinInstaller $dropin;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function logger(): Logger { return $this->logger; }
    public function purge(): PurgeManager { return $this->purge; }
    public function dropin(): DropinInstaller { return $this->dropin; }

    public static function boot(): void
    {
        $self = self::instance();
        $self->logger = new Logger();
        $self->dropin = new DropinInstaller(new DropinGenerator());
        $self->purge  = new PurgeManager(
            $self->logger,
            new NginxStrategy(),
            new OpcacheStrategy(),
            new RedisStrategy(),
            new ElementorStrategy(),
            ...( get_option('vlt_litespeed_purge') || \VLT\CacheManager\ServerDetector::isLiteSpeed() ? [new LiteSpeedStrategy()] : [] )
        );

        // Targeted cache invalidation — hooks into every content change event
        (new \VLT\CacheManager\Cache\CacheInvalidator($self->purge))->register();

        add_action('init', [$self->logger, 'logCfRequest']);
        add_action('shutdown', [$self, 'onShutdown']);

        add_action('vlt_cm_log_rotate', [$self->logger, 'rotateLogs']);
        if (!wp_next_scheduled('vlt_cm_log_rotate')) {
            wp_schedule_event(time(), 'daily', 'vlt_cm_log_rotate');
        }

        add_action('vlt_trace_rotate', [$self, 'rotateTraces']);
        if (!wp_next_scheduled('vlt_trace_rotate')) {
            wp_schedule_event(time(), 'daily', 'vlt_trace_rotate');
        }

        if (is_admin()) {
            add_action('admin_menu', [$self, 'registerMenu']);
            add_action('admin_bar_menu', [$self, 'adminBar'], 100);
            add_action('admin_init', [$self, 'handleActions']);
            add_action('wp_dashboard_setup', [$self, 'dashboardWidget']);
            add_action('admin_enqueue_scripts', [$self, 'enqueueAdminAssets']);

            if (!function_exists('simdjson_decode')) {
                add_action('admin_notices', [$self, 'simdjsonNotice']);
            }

            if (\VLT\CacheManager\CloudLinuxDetector::isCloudLinux()) {
                add_action('admin_notices', [$self, 'cloudLinuxNotice']);
            }

            add_action('admin_notices', [$self, 'imgOptmNotice']);

            if (!$self->dropin->isOurs()) {
                add_action('admin_notices', [$self, 'dropinNotice']);
            }
        }

        // REST API routes (available to logged-in admins)
        add_action('rest_api_init', [Admin\RestApi::class, 'register']);
        add_action('rest_api_init', [GratisRestApi::class, 'register']);

        // Image optimization
        \VLT\CacheManager\Image\ImageOptimizer::register();

        // Page cache (full HTML caching)
        \VLT\CacheManager\Cache\PageCache::register();

        // Performance: Critical CSS, Minification, Resource Hints
        \VLT\CacheManager\Performance\CriticalCSS::register();
        \VLT\CacheManager\Performance\Minifier::register();
        \VLT\CacheManager\Performance\ResourceHints::register();
        \VLT\CacheManager\Performance\HeartbeatControl::register();
        \VLT\CacheManager\Performance\LazyLoad::register();
        \VLT\CacheManager\Performance\RedirectManager::register();
        \VLT\CacheManager\Search\SearchIndex::register();
        \VLT\CacheManager\Admin\DashboardWidget::register();
        \VLT\CacheManager\Security\LoginProtection::register();

        // Native LiteSpeed cache control (sends X-LiteSpeed-Cache-Control headers)
        if (\VLT\CacheManager\ServerDetector::isLiteSpeed()) {
            \VLT\CacheManager\Cache\LiteSpeedCache::register();
        }

        // Error guard: prevent ALL cache layers from storing error responses
        \VLT\CacheManager\Cache\ErrorGuard::register();

        // Async queue worker endpoint
        add_action('wp_ajax_nopriv_vlt_async_worker', [\VLT\CacheManager\Async\AsyncQueue::class, 'processQueue']);
        add_action('wp_ajax_vlt_async_worker',        [\VLT\CacheManager\Async\AsyncQueue::class, 'processQueue']);

        // WP-Cron → Redis offload
        \VLT\CacheManager\Async\AsyncQueue::offloadCron();

        // Cron execution monitoring
        \VLT\CacheManager\Async\CronMonitor::register();

        // Trace worker keepalive — check every 5 minutes via WP-Cron
        add_action('vlt_trace_worker_check', [\VLT\CacheManager\Tracer\TraceWorker::class, 'ensureRunning']);
        if (!wp_next_scheduled('vlt_trace_worker_check')) {
            wp_schedule_event(time(), 'vlt_five_minutes', 'vlt_trace_worker_check');
        }
        add_filter('cron_schedules', function ($s) {
            $s['vlt_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
            return $s;
        });

        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('vlt-cache', new CLI\CacheCommand($self));
            \WP_CLI::add_command('gratis-cache', \VLT\CacheManager\CLI\GratisCacheCommand::class);
        }
    }

    public function onShutdown(): void
    {
        $oc = $GLOBALS['wp_object_cache'] ?? null;
        if ($oc && property_exists($oc, 'cache_hits')) {
            $this->logger->logRequestStats($oc->cache_hits, $oc->cache_misses);
        }
    }

    public function rotateTraces(): void
    {
        $dir = TracerConfig::getDir();
        $cut = gmdate('Y-m-d', strtotime('-7 days'));
        foreach (glob($dir . '/trace-*.json') as $f) {
            if (preg_match('/trace-(\d{4}-\d{2}-\d{2})\.json$/', $f, $m) && $m[1] < $cut) {
                @unlink($f);
            }
        }
        $maxMb = (int) get_option('vlt_cm_trace_max_mb', 200);
        if ($maxMb > 0) {
            \VLT\CacheManager\Log\Logger::enforceMaxSize($dir, 'trace-*.json', $maxMb * 1048576);
        }
    }

    public function enqueueAdminAssets(string $hook): void
    {
        // Only on our plugin pages
        if (!str_contains($hook, 'vlt-cache')) {
            return;
        }
        wp_enqueue_script('vlt-tailwind', 'https://cdn.tailwindcss.com', [], null, false);
        // Alpine must load in footer (after body) to avoid "Alpine before body" warning
        wp_enqueue_script('vlt-alpine', 'https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.14.9/cdn.min.js', [], null, true);
        // Prefix all Tailwind utilities with tw- to avoid conflicts with WP admin classes
        // e.g. WP uses .fixed for table-layout:fixed, Tailwind uses .fixed for position:fixed
        wp_add_inline_script('vlt-tailwind', 'tailwind.config={prefix:"tw-",theme:{extend:{colors:{"wp-blue":"#2271b1","wp-green":"#46b450","wp-red":"#dc3232","wp-yellow":"#f0b849"}}}}');
    }

    public function registerMenu(): void
    {
        $server = \VLT\CacheManager\ServerDetector::detect()['server'];
        $isLS   = in_array($server, [\VLT\CacheManager\ServerDetector::LITESPEED, \VLT\CacheManager\ServerDetector::OLS], true);
        $isNginx  = $server === \VLT\CacheManager\ServerDetector::NGINX;
        $isApache = $server === \VLT\CacheManager\ServerDetector::APACHE;

        $host    = parse_url(home_url(), PHP_URL_HOST) ?? '';
        $hasCf   = \VLT\CacheManager\Admin\Page\SettingsPage::isDomainBehindCloudflare($host)
                   || get_option('vlt_cm_cf_tracking', false);

        $pages = [new DashboardPage(), new LogsPage()];
        if ($hasCf) {
            $pages[] = new CloudflarePage();
        }

        // Show server-specific page, hide others
        if ($isLS) {
            $pages[] = new LiteSpeedPage();
        } elseif ($isNginx) {
            $pages[] = new NginxExplorerPage();
        } elseif ($isApache) {
            $pages[] = new ApachePage();
        } else {
            // Unknown — show all
            $pages[] = new NginxExplorerPage();
        }

        $pages[] = new OpcacheExplorerPage();
        $pages[] = new RedisExplorerPage();
        $pages[] = new TracerPage();
        $pages[] = new PerformancePage();
        if (\VLT\CacheManager\CloudLinuxDetector::isCloudLinux()) {
            $pages[] = new CloudLinuxPage();
        }
        $pages[] = new DocsPage();
        $pages[] = new SettingsPage();

        add_menu_page(
            __('Cache Manager', 'juslintek-cache-manager'),
            __('Cache Manager', 'juslintek-cache-manager'),
            'manage_options', 'vlt-cache', [$pages[0], 'render'], 'dashicons-performance', 80
        );
        add_submenu_page('vlt-cache', __('Dashboard', 'juslintek-cache-manager'), __('Dashboard', 'juslintek-cache-manager'), 'manage_options', 'vlt-cache', [$pages[0], 'render']);

        for ($i = 1, $c = count($pages); $i < $c; $i++) {
            $title = __($pages[$i]->title(), 'juslintek-cache-manager');
            add_submenu_page('vlt-cache', $title, $title, 'manage_options', $pages[$i]->slug(), [$pages[$i], 'render']);
        }
    }

    public function adminBar(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $hits = $misses = 0;
        if (isset($GLOBALS['wp_object_cache']) && property_exists($GLOBALS['wp_object_cache'], 'cache_hits')) {
            $hits   = $GLOBALS['wp_object_cache']->cache_hits;
            $misses = $GLOBALS['wp_object_cache']->cache_misses;
        }
        $wp_admin_bar->add_node([
            'id'    => 'vlt-cache',
            'title' => sprintf(__('Cache Manager (H:%d M:%d)', 'juslintek-cache-manager'), $hits, $misses),
            'href'  => admin_url('admin.php?page=vlt-cache'),
        ]);
        $isLS = \VLT\CacheManager\ServerDetector::isLiteSpeed();
        $items = [
            'purge-all'     => [__('Purge all', 'juslintek-cache-manager'), 'all'],
            'purge-server'  => [$isLS ? __('Purge LiteSpeed', 'juslintek-cache-manager') : __('Purge Nginx', 'juslintek-cache-manager'), $isLS ? 'litespeed' : 'nginx'],
            'purge-opcache' => [__('Purge OPcache', 'juslintek-cache-manager'), 'opcache'],
            'purge-redis'   => [__('Purge Redis', 'juslintek-cache-manager'), 'redis'],
            'debug-toggle'  => [isset($_COOKIE['vlt_debug_cache']) ? __('Disable debug', 'juslintek-cache-manager') : __('Enable debug', 'juslintek-cache-manager'), 'debug'],
        ];
        foreach ($items as $id => $item) {
            $href = $item[1] === 'debug'
                ? wp_nonce_url(admin_url('admin.php?action=vlt_toggle_debug'), 'vlt_toggle_debug')
                : wp_nonce_url(admin_url('admin.php?action=vlt_purge&type=' . $item[1]), 'vlt_purge');
            $wp_admin_bar->add_node([
                'parent' => 'vlt-cache',
                'id'     => 'vlt-' . $id,
                'title'  => $item[0],
                'href'   => $href,
            ]);
        }
    }

    public function handleActions(): void
    {
        $action = $_GET['action'] ?? '';

        if ($action === 'vlt_purge') {
            @ini_set('memory_limit', '768M');
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_purge') || !current_user_can('manage_options')) {
                wp_die('Neautorizuota');
            }
            // Redirect to dashboard — purging now happens via SSE stream from the UI
            // This prevents the old direct URL from crashing with OOM
            wp_safe_redirect(admin_url('admin.php?page=vlt-cache&vlt_purge_redirect=1'));
            exit;
        }

        if ($action === 'vlt_install_dropin') {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_install_dropin') || !current_user_can('manage_options')) {
                wp_die('Neautorizuota');
            }
            $this->dropin->install();
            wp_safe_redirect(admin_url('admin.php?page=vlt-cache-settings&dropin_installed=1'));
            exit;
        }

        if ($action === 'vlt_toggle_debug') {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_toggle_debug') || !current_user_can('manage_options')) {
                wp_die('Neautorizuota');
            }
            if (isset($_COOKIE['vlt_debug_cache'])) {
                setcookie('vlt_debug_cache', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            } else {
                setcookie('vlt_debug_cache', '1', 0, COOKIEPATH, COOKIE_DOMAIN);
            }
            wp_safe_redirect(wp_get_referer() ?: admin_url());
            exit;
        }

        if ($action === 'vlt_trace_toggle') {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_trace_toggle') || !current_user_can('manage_options')) {
                return;
            }
            if (isset($_COOKIE['vlt_trace'])) {
                setcookie('vlt_trace', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            } else {
                setcookie('vlt_trace', '1', time() + 86400, COOKIEPATH, COOKIE_DOMAIN);
            }
            wp_redirect(admin_url('admin.php?page=vlt-cache-tracer'));
            exit;
        }
    }

    public function dashboardWidget(): void
    {
        wp_add_dashboard_widget('vlt_cache_widget', 'Podėlio Valdymas', [$this, 'renderDashboardWidget']);
    }

    public function renderDashboardWidget(): void
    {
        $redis = self::redisInfo();
        $stats = $this->logger->getTodayStats();
        $ratio = ($stats['hits'] + $stats['misses']) > 0
            ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 1) : 0;

        echo '<p><strong>Redis:</strong> ' . ($redis['connected']
            ? 'Prijungtas (' . esc_html($redis['memory']) . ')'
            : '<span style="color:red">Nepasiekiamas</span>') . '</p>';
        echo '<p><strong>Pataikymų santykis šiandien:</strong> ' . $ratio . '% (' . $stats['hits'] . '/' . ($stats['hits'] + $stats['misses']) . ')</p>';
        echo '<p><strong>Nginx talpykla:</strong> ' . esc_html(self::formatSize(self::dirSize(VLT_CM_NGINX_CACHE))) . '</p>';

        $entries = $this->logger->readLog(gmdate('Y-m-d'));
        $purges  = array_filter($entries, fn($e) => ($e['type'] ?? '') === 'purge');
        $purges  = array_reverse($purges);
        $groups  = [];
        foreach ($purges as $p) {
            $key = substr($p['timestamp'] ?? '', 0, 19) . '|' . ($p['user_id'] ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = ['timestamp' => $p['timestamp'], 'user_name' => $p['user_name'] ?? 'Sistema', 'types' => []];
            }
            $groups[$key]['types'][] = is_array($p['details']) ? implode(', ', $p['details']) : $p['details'];
        }
        $groups = array_slice($groups, 0, 5);
        if ($groups) {
            echo '<p><strong>Paskutiniai valymai:</strong></p><ul style="margin:0">';
            foreach ($groups as $g) {
                echo '<li>' . esc_html($g['timestamp']) . ' — <em>' . esc_html($g['user_name']) . '</em>: ' . esc_html(implode(', ', $g['types'])) . '</li>';
            }
            echo '</ul>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=vlt-cache-logs&log_filter=purge')) . '">Visi žurnalai →</a></p>';
        }
    }

    public function imgOptmNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $hasVips    = extension_loaded('vips') || class_exists('Vips\Image') || self::commandExists('vips');
        $hasAvif    = class_exists('Imagick') && in_array('AVIF', \Imagick::queryFormats(), true);
        $hasWebp    = class_exists('Imagick') && in_array('WEBP', \Imagick::queryFormats(), true);
        $hasGdWebp  = function_exists('imagewebp');
        $allOptimal = $hasVips && $hasAvif && ($hasWebp || $hasGdWebp);

        // If everything is now installed, clear the dismissed flag so success notice shows once
        if ($allOptimal && get_option('vlt_img_optm_notice_dismissed')) {
            delete_option('vlt_img_optm_notice_dismissed');
        }

        if (get_option('vlt_img_optm_notice_dismissed')) {
            return;
        }

        $nonce = wp_create_nonce('wp_rest');
        $url   = esc_js(rest_url('vlt-cache/v1/dismiss-notice'));

        if ($allOptimal) {
            // Green success — auto-dismiss after 4s
            echo '<div class="notice notice-success is-dismissible" id="vlt-img-optm-notice"><p>';
            echo '✅ <strong>Paveikslėlių optimizavimas:</strong> ';
            echo 'libvips ' . ($hasVips ? '✅' : '❌') . ' &nbsp; ';
            echo 'AVIF ' . ($hasAvif ? '✅' : '❌') . ' &nbsp; ';
            echo 'WebP ' . ($hasWebp || $hasGdWebp ? '✅' : '❌') . ' — ';
            echo 'Visi įrankiai įdiegti. Optimalus našumas.';
            echo '</p></div>';
            echo '<script>
            setTimeout(function() {
                var n = document.getElementById("vlt-img-optm-notice");
                if (n) n.style.display = "none";
                fetch("' . $url . '", {method:"POST",headers:{"X-WP-Nonce":"' . $nonce . '","Content-Type":"application/json"},body:JSON.stringify({notice:"vlt_img_optm_notice_dismissed"})});
            }, 4000);
            </script>';
            return;
        }

        // Build missing list
        $missing = [];
        $tips    = [];
        if (!$hasVips) {
            $missing[] = 'libvips <small>(~10× greičiau už ImageMagick)</small>';
            $tips[]    = '<code>sudo apt install libvips-tools php-vips</code>';
        }
        if (!$hasAvif) {
            $missing[] = 'Imagick AVIF <small>(~50% mažiau nei WebP)</small>';
            $tips[]    = 'Rekompiliuokite ImageMagick su libavif: <code>sudo dnf install --enablerepo=remi vips-devel</code>';
        }
        if (!$hasWebp && !$hasGdWebp) {
            $missing[] = 'WebP palaikymas (Imagick arba GD)';
            $tips[]    = '<code>sudo apt install php-gd</code> arba <code>pecl install imagick</code>';
        }

        // Conflicting plugins
        $activeOptimizers = [];
        foreach ([
            'shortpixel-image-optimiser/wp-shortpixel.php' => 'ShortPixel',
            'imagify/imagify.php'                           => 'Imagify',
            'wp-smushit/wp-smush.php'                      => 'Smush',
            'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW',
            'webp-express/webp-express.php'                 => 'WebP Express',
            'optimole-wp/optimole-wp.php'                   => 'Optimole',
        ] as $slug => $name) {
            if (in_array($slug, (array) get_option('active_plugins', []), true)) {
                $activeOptimizers[] = $name;
            }
        }

        if (empty($missing) && empty($activeOptimizers)) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible" id="vlt-img-optm-notice"><p>';
        echo '<strong>Paveikslėlių optimizavimas:</strong> ';
        if (!empty($activeOptimizers)) {
            echo 'Aktyvūs keli optimizavimo įskiepiai: <strong>' . implode(', ', $activeOptimizers) . '</strong> — gali konfliktuoti. ';
        }
        if (!empty($missing)) {
            echo 'Trūksta: ' . implode(', ', $missing) . '. ';
            echo '<details style="margin-top:4px"><summary style="cursor:pointer">Kaip įdiegti ▾</summary><ul style="margin:4px 0 0 16px">';
            foreach ($tips as $tip) {
                echo '<li>' . $tip . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</p></div>';
        echo '<script>
        document.querySelector("#vlt-img-optm-notice .notice-dismiss")?.addEventListener("click", function() {
            fetch("' . $url . '", {method:"POST",headers:{"X-WP-Nonce":"' . $nonce . '","Content-Type":"application/json"},body:JSON.stringify({notice:"vlt_img_optm_notice_dismissed"})});
        });
        </script>';
    }

    private static function commandExists(string $cmd): bool
    {
        foreach (explode(':', getenv('PATH') ?: '/usr/bin:/usr/local/bin:/bin') as $dir) {
            if (@is_executable(rtrim($dir, '/') . '/' . $cmd)) {
                return true;
            }
        }
        return false;
    }

    public function cloudLinuxNotice(): void
    {
        if (!current_user_can('manage_options') || get_option('vlt_cl_notice_dismissed')) {
            return;
        }
        $missing = [];
        if (!\VLT\CacheManager\CloudLinuxDetector::redisEnabled()) {
            $missing[] = 'Redis object cache';
        }
        if (empty($missing)) {
            return;
        }
        $nonce = wp_create_nonce('wp_rest');
        $url   = esc_js(rest_url('vlt-cache/v1/dismiss-notice'));
        echo '<div class="notice notice-info is-dismissible" id="vlt-cl-notice"><p>';
        echo '☁ <strong>CloudLinux aptiktas.</strong> Neaktyvios optimizacijos: <strong>' . implode(', ', $missing) . '</strong>. ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=vlt-cache-cloudlinux')) . '">Žiūrėti CloudLinux puslapį →</a>';
        echo '</p></div>';
        echo '<script>document.querySelector("#vlt-cl-notice .notice-dismiss")?.addEventListener("click",()=>{fetch("' . $url . '",{method:"POST",headers:{"X-WP-Nonce":"' . $nonce . '","Content-Type":"application/json"},body:JSON.stringify({notice:"vlt_cl_notice_dismissed"})});});</script>';
    }

    public function simdjsonNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $panel = \VLT\CacheManager\Redis\RedisDetector::detectPanel();
        $instructions = match ($panel) {
            'cpanel'      => 'WHM → PHP Extensions → simdjson, arba SSH: <code>pecl install simdjson</code>',
            'plesk'       => 'Plesk → PHP Settings → Extensions → simdjson, arba SSH: <code>pecl install simdjson</code>',
            'directadmin' => 'CustomBuild → PHP Extensions → simdjson, arba SSH: <code>pecl install simdjson</code>',
            default       => 'Ubuntu/Debian: <code>sudo apt install php-simdjson</code> arba <code>sudo pecl install simdjson</code> + pridėkite <code>extension=simdjson</code> į php.ini',
        };
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>Podėlio Valdymas:</strong> <code>simdjson</code> PHP plėtinys neįdiegtas. ';
        echo 'Žurnalų ir pėdsakų skaitymas naudoja lėtesnį <code>json_decode</code>. ';
        echo 'Įdiegimui: ' . $instructions . '. ';
        echo 'Po įdiegimo paleiskite iš naujo PHP-FPM: <code>sudo systemctl restart php-fpm</code>';
        echo '</p></div>';
    }

    public function dropinNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>VLT Object Cache drop-in neįdiegtas. <a href="' .
            esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_install_dropin'), 'vlt_install_dropin')) .
            '">Įdiegti dabar</a></p></div>';
    }

    public static function redisInfo(): array
    {
        $info = ['connected' => false, 'memory' => '—', 'keys' => 0];
        try {
            $r = \VLT\CacheManager\Redis\RedisFactory::create(1.0);
            if ($r) {
                $info['connected'] = true;
                $ri = $r->info();
                $info['memory'] = $ri['used_memory_human'] ?? '—';
                $info['keys']   = (int) ($ri['db0']['keys'] ?? array_sum(array_map(
                    fn($v) => $v['keys'] ?? 0,
                    array_filter($ri, fn($v, $k) => str_starts_with($k, 'db') && is_array($v), ARRAY_FILTER_USE_BOTH)
                )));
                $r->close();
            }
        } catch (\Exception $e) {
        }
        return $info;
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $s = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $s += $f->getSize();
        }
        return $s;
    }

    public static function purgeButton(string $type, string $label): string
    {
        return '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_purge&type=' . $type), 'vlt_purge')) . '" class="button">' . esc_html($label) . '</a> ';
    }

    public static function notice(): void
    {
        if (!empty($_GET['vlt_purged'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Talpykla sėkmingai išvalyta.</p></div>';
        }
    }
}
