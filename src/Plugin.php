<?php

declare(strict_types=1);

namespace VLT\CacheManager;

use VLT\CacheManager\Admin\AdminAjax;
use VLT\CacheManager\Admin\Page\CloudflarePage;
use VLT\CacheManager\Admin\Page\DashboardPage;
use VLT\CacheManager\Admin\Page\LogsPage;
use VLT\CacheManager\Admin\Page\NginxExplorerPage;
use VLT\CacheManager\Admin\Page\OpcacheExplorerPage;
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
            ...( get_option('vlt_litespeed_purge') ? [new LiteSpeedStrategy()] : [] )
        );

        add_action('init', [$self->logger, 'logCfRequest']);
        add_action('shutdown', [$self, 'onShutdown']);
        add_action('save_post', [$self, 'onPostChange']);
        add_action('delete_post', [$self, 'onPostChange']);
        add_action('trashed_post', [$self, 'onPostChange']);
        add_action('switch_theme', [$self->purge, 'purgeAll']);
        add_action('customize_save_after', [$self->purge, 'purgeAll']);
        add_action('upgrader_process_complete', [$self, 'onUpgrade'], 10, 2);
        add_action('elementor/core/files/clear_cache', fn() => $self->purge->purge('nginx'));

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

            if (!$self->dropin->isOurs()) {
                add_action('admin_notices', [$self, 'dropinNotice']);
            }
        }

        // REST API routes (available to logged-in admins)
        add_action('rest_api_init', [Admin\RestApi::class, 'register']);

        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('vlt-cache', new CLI\CacheCommand($self));
        }
    }

    public function onPostChange(int $id): void
    {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id)) {
            return;
        }
        $this->purge->purge('nginx');
    }

    public function onUpgrade($upgrader, $options): void
    {
        $this->purge->purge('opcache');
        $this->purge->purge('nginx');
        $this->purge->purge('elementor');
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

    public function registerMenu(): void
    {
        $pages = [
            new DashboardPage(),
            new LogsPage(),
            new CloudflarePage(),
            new NginxExplorerPage(),
            new OpcacheExplorerPage(),
            new RedisExplorerPage(),
            new TracerPage(),
            new SettingsPage(),
        ];

        add_menu_page('Podėlio Valdymas', 'Podėlio Valdymas', 'manage_options', 'vlt-cache', [$pages[0], 'render'], 'dashicons-performance', 80);
        add_submenu_page('vlt-cache', 'Suvestinė', 'Suvestinė', 'manage_options', 'vlt-cache', [$pages[0], 'render']);

        for ($i = 1, $c = count($pages); $i < $c; $i++) {
            add_submenu_page('vlt-cache', $pages[$i]->title(), $pages[$i]->title(), 'manage_options', $pages[$i]->slug(), [$pages[$i], 'render']);
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
            'title' => "Podėlio Valdymas (H:$hits M:$misses)",
            'href'  => admin_url('admin.php?page=vlt-cache'),
        ]);
        $items = [
            'purge-all'     => ['Valyti viską', 'all'],
            'purge-nginx'   => ['Valyti Nginx', 'nginx'],
            'purge-opcache' => ['Valyti OPcache', 'opcache'],
            'debug-toggle'  => [isset($_COOKIE['vlt_debug_cache']) ? 'Išjungti debug' : 'Įjungti debug', 'debug'],
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
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_purge') || !current_user_can('manage_options')) {
                wp_die('Neautorizuota');
            }
            $this->purge->purge(sanitize_key($_GET['type'] ?? 'all'));
            wp_safe_redirect(add_query_arg('vlt_purged', '1', wp_get_referer() ?: admin_url()));
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
            $r = new \Redis();
            if ($r->connect('127.0.0.1', 6379, 1.0)) {
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
