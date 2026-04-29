<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Plugin;

final class DashboardPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache'; }
    public function title(): string { return 'Suvestinė'; }

    public function render(): void
    {
        $p       = Plugin::instance();
        $redis   = Plugin::redisInfo();
        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
        $nginx_size = Plugin::dirSize(VLT_CM_NGINX_CACHE);
        $el_dir  = WP_CONTENT_DIR . '/uploads/elementor/css/';
        $el_count = is_dir($el_dir) ? count(glob($el_dir . '*.css')) : 0;
        $stats   = $p->logger()->getTodayStats();
        $ratio   = ($stats['hits'] + $stats['misses']) > 0
            ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 1) : 0;

        Plugin::notice();
        echo '<div class="wrap"><h1>Podėlio Valdymas — Suvestinė</h1>';

        echo '<table class="widefat fixed striped" style="max-width:700px;margin:20px 0"><thead><tr><th>Talpykla</th><th>Būsena</th></tr></thead><tbody>';
        echo '<tr><td>Redis</td><td>' . ($redis['connected'] ? 'Prijungtas — ' . esc_html($redis['memory']) . ', ' . $redis['keys'] . ' raktų' : '<span style="color:red">Nepasiekiamas</span>') . '</td></tr>';
        if ($opcache) {
            $oc_used  = Plugin::formatSize($opcache['memory_usage']['used_memory'] ?? 0);
            $oc_files = $opcache['opcache_statistics']['num_cached_scripts'] ?? 0;
            $oc_hit   = $opcache['opcache_statistics']['opcache_hit_rate'] ?? 0;
            echo '<tr><td>OPcache</td><td>' . esc_html($oc_used) . ', ' . $oc_files . ' failų, ' . round($oc_hit, 1) . '% pataikymų</td></tr>';
        } else {
            echo '<tr><td>OPcache</td><td>Išjungtas</td></tr>';
        }
        echo '<tr><td>Nginx FastCGI</td><td>' . esc_html(Plugin::formatSize($nginx_size)) . '</td></tr>';
        echo '<tr><td>Elementor CSS</td><td>' . $el_count . ' failų</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Šiandienos statistika</h2>';
        echo '<table class="widefat fixed striped" style="max-width:700px"><tbody>';
        echo '<tr><td>Užklausų užregistruota</td><td>' . $stats['requests'] . '</td></tr>';
        echo '<tr><td>Pataikymų santykis</td><td>' . $ratio . '%</td></tr>';
        echo '<tr><td>Valymo įvykiai</td><td>' . $stats['purges'] . '</td></tr>';
        echo '</tbody></table>';

        $entries = $p->logger()->readLog(gmdate('Y-m-d'));
        $purges  = array_filter($entries, fn($e) => ($e['type'] ?? '') === 'purge');
        $purges  = array_reverse($purges);

        $groups = [];
        foreach ($purges as $pg) {
            $key = substr($pg['timestamp'] ?? '', 0, 19) . '|' . ($pg['user_id'] ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = ['timestamp' => $pg['timestamp'], 'user_name' => $pg['user_name'] ?? 'Sistema', 'user_id' => $pg['user_id'] ?? 0, 'ip' => $pg['ip'] ?? '', 'types' => []];
            }
            $groups[$key]['types'][] = is_array($pg['details']) ? implode(', ', $pg['details']) : $pg['details'];
        }
        $groups = array_slice($groups, 0, 10);

        if ($groups) {
            echo '<h2>Paskutiniai valymo įvykiai</h2>';
            echo '<style>.vlt-purge-group{cursor:pointer;user-select:none}.vlt-purge-detail{display:none;padding:5px 20px;background:#f9f9f9}.vlt-purge-group.open+.vlt-purge-detail{display:table-row}</style>';
            echo '<table class="widefat fixed striped" style="max-width:700px"><thead><tr><th>Laikas</th><th>Kas valė</th><th>Kas išvalyta</th></tr></thead><tbody>';
            foreach ($groups as $g) {
                $types_str  = implode(', ', $g['types']);
                $user_label = esc_html($g['user_name']);
                if ($g['user_id']) {
                    $user_label .= ' (ID:' . $g['user_id'] . ')';
                }
                echo '<tr class="vlt-purge-group" onclick="this.classList.toggle(\'open\')">';
                echo '<td>▸ ' . esc_html($g['timestamp']) . '</td>';
                echo '<td>' . $user_label . '</td>';
                echo '<td>' . esc_html($types_str) . '</td></tr>';
                echo '<tr class="vlt-purge-detail"><td colspan="3">';
                echo '<strong>IP:</strong> ' . esc_html($g['ip']) . '<br>';
                echo '<strong>Išvalytos talpyklos:</strong> ';
                foreach ($g['types'] as $t) {
                    echo '<span style="display:inline-block;background:#e0e0e0;padding:2px 8px;margin:2px;border-radius:3px">' . esc_html($t) . '</span>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=vlt-cache-logs&log_filter=purge')) . '">Žiūrėti visus valymo žurnalus →</a></p>';
        }

        echo '<h2>Greitas valymas</h2><p>';
        echo Plugin::purgeButton('all', 'Viską');
        echo Plugin::purgeButton('nginx', 'Nginx');
        echo Plugin::purgeButton('opcache', 'OPcache');
        echo Plugin::purgeButton('redis', 'Redis');
        echo Plugin::purgeButton('elementor', 'Elementor');
        echo '</p></div>';
    }
}
