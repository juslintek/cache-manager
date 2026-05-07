<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Plugin;
use VLT\CacheManager\ServerDetector;

final class LiteSpeedPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-litespeed'; }
    public function title(): string { return 'LiteSpeed'; }

    public function render(): void
    {
        $info = ServerDetector::detect();
        $isOls = $info['server'] === ServerDetector::OLS;
        $label = $isOls ? 'OpenLiteSpeed' : 'LiteSpeed Enterprise';

        Plugin::notice();
        echo '<div class="wrap"><h1>Podėlio Valdymas — ' . esc_html($label) . '</h1>';

        // Status panel
        echo '<table class="widefat fixed striped" style="max-width:700px;margin:20px 0"><tbody>';
        echo '<tr><td><strong>Serveris</strong></td><td>' . esc_html($label) . ($info['version'] ? ' v' . esc_html($info['version']) : '') . '</td></tr>';
        echo '<tr><td><strong>Konfigūracijos failas</strong></td><td><code>' . esc_html($info['config']['config_file'] ?? '—') . '</code></td></tr>';
        echo '<tr><td><strong>LSCache modulis</strong></td><td>';
        $lscacheSo = @file_exists('/usr/local/lsws/modules/mod_lscache.so') || @file_exists('/usr/local/lsws/modules/lscache.so');
        if ($info['config']['lscache'] ?? false) {
            echo '<span style="color:#46b450">✅ Aktyvus</span>';
            // Show which signals confirmed it
            $signals = [];
            if ($info['config']['lscache_php_api'] ?? false) $signals[] = 'PHP API';
            if ($info['config']['lscache_headers'] ?? false)  $signals[] = 'HTTP antraštės';
            if ($info['config']['lscache_so'] ?? false)       $signals[] = 'modulis (.so)';
            if ($info['config']['lscache_conf'] ?? false)     $signals[] = 'konfigūracija';
            if ($info['config']['lscache_storage'] ?? false)  $signals[] = 'talpyklos katalogas';
            if ($signals) echo ' <small style="color:#666">(' . implode(', ', $signals) . ')</small>';
        } elseif ($lscacheSo) {
            echo '<span style="color:#dba617">⚠ Modulis rastas, bet neįjungtas konfigūracijoje</span>';
        } else {
            echo '<span style="color:#d63638">❌ Nerastas — įjunkite LSCache modulį</span>';
        }
        echo '</td></tr>';
        if (!empty($info['config']['lscache_storage_path'])) {
            echo '<tr><td><strong>Talpyklos katalogas</strong></td><td><code>' . esc_html($info['config']['lscache_storage_path']) . '</code></td></tr>';
        }
        echo '<tr><td><strong>LSCWP įskiepis</strong></td><td>' . (defined('LSCWP_V') || class_exists('LiteSpeed\Core') ? '<span style="color:#46b450">✅ Aktyvus</span>' : '<span style="color:#d63638">❌ Neaktyvus</span>') . '</td></tr>';
        echo '</tbody></table>';

        // Recommendations
        if (!empty($info['recommendations'])) {
            echo '<h2>Rekomendacijos</h2><ul>';
            foreach ($info['recommendations'] as $rec) {
                echo '<li>⚠ ' . esc_html($rec) . '</li>';
            }
            echo '</ul>';
        }

        // Cache purge actions
        echo '<h2>Talpyklos valdymas</h2><p>';
        echo Plugin::purgeButton('litespeed', 'Valyti LiteSpeed talpyklą');
        echo Plugin::purgeButton('all', 'Valyti viską');
        echo '</p>';

        // LSCWP link
        if (!defined('LSCWP_V') && !class_exists('LiteSpeed\Core')) {
            echo '<div class="notice notice-warning inline"><p>';
            echo 'LiteSpeed Cache WordPress įskiepis neaktyvus. ';
            echo '<a href="' . esc_url(admin_url('plugin-install.php?s=litespeed+cache&tab=search&type=term')) . '">Įdiegti dabar →</a>';
            echo '</p></div>';
        } else {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=litespeed')) . '" class="button">Atidaryti LSCWP nustatymus →</a></p>';
        }

        // Config file preview — show all relevant configs
        $configFiles = array_filter([
            $info['config']['config_file'] ?? '',
            '/etc/openlitespeed/httpd_config.conf',
            '/etc/openlitespeed/httpd-vhosts.conf',
            '/usr/local/lsws/conf/httpd_config.conf',
            '/usr/local/directadmin/data/templates/openlitespeed_vhost.conf',
            '/usr/local/directadmin/data/templates/custom/openlitespeed_vhost.conf',
        ]);
        $shown = [];
        foreach ($configFiles as $configFile) {
            if (!$configFile || isset($shown[$configFile]) || !@is_readable($configFile)) {
                continue;
            }
            $shown[$configFile] = true;
            echo '<h2>Konfigūracijos failas: <code>' . esc_html($configFile) . '</code></h2>';
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:400px;font-size:12px">';
            echo esc_html(substr(@file_get_contents($configFile) ?: '(nepasiekiamas)', 0, 50000));
            echo '</pre>';
        }

        // Show vhost configs
        $vhostDirs = ['/usr/local/lsws/conf/vhosts/', '/etc/openlitespeed/vhosts/'];
        foreach ($vhostDirs as $dir) {
            foreach (@glob($dir . '*/vhconf.conf') ?: [] as $vhconf) {
                if (!@is_readable($vhconf)) {
                    continue;
                }
                echo '<h2>VHost: <code>' . esc_html($vhconf) . '</code></h2>';
                echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:300px;font-size:12px">';
                echo esc_html(substr(@file_get_contents($vhconf) ?: '', 0, 30000));
                echo '</pre>';
            }
        }

        echo '</div>';
    }
}
