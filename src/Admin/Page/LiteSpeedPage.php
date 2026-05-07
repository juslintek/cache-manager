<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Cache\LiteSpeedCache;
use VLT\CacheManager\Plugin;
use VLT\CacheManager\ServerDetector;

final class LiteSpeedPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-litespeed'; }
    public function title(): string { return 'LiteSpeed'; }

    public function render(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('vlt_ls_settings')) {
            update_option('vlt_ls_cache_enabled',    isset($_POST['vlt_ls_cache_enabled']));
            update_option('vlt_ls_cache_ttl',        max(60, (int) ($_POST['vlt_ls_cache_ttl'] ?? 86400)));
            update_option('vlt_ls_cache_logged_in',  isset($_POST['vlt_ls_cache_logged_in']));
            update_option('vlt_ls_cache_search',     isset($_POST['vlt_ls_cache_search']));
            update_option('vlt_ls_cache_404',        isset($_POST['vlt_ls_cache_404']));
            echo '<div class="notice notice-success"><p>Nustatymai išsaugoti.</p></div>';
        }

        $info    = ServerDetector::detect();
        $isOls   = $info['server'] === ServerDetector::OLS;
        $label   = $isOls ? 'OpenLiteSpeed' : 'LiteSpeed Enterprise';
        $enabled = get_option('vlt_ls_cache_enabled', true);
        $ttl     = (int) get_option('vlt_ls_cache_ttl', 86400);

        Plugin::notice();
        echo '<div class="wrap"><h1>Podėlio Valdymas — ' . esc_html($label) . '</h1>';

        // ── Status ────────────────────────────────────────────────────────────
        echo '<table class="widefat fixed striped" style="max-width:700px;margin:20px 0"><tbody>';
        echo '<tr><td><strong>Serveris</strong></td><td>' . esc_html($label) . ($info['version'] ? ' v' . esc_html($info['version']) : '') . '</td></tr>';
        echo '<tr><td><strong>Konfigūracijos failas</strong></td><td><code>' . esc_html($info['config']['config_file'] ?? '—') . '</code></td></tr>';

        // LSCache status with signal breakdown
        echo '<tr><td><strong>LSCache modulis</strong></td><td>';
        $lscacheSo = @file_exists('/usr/local/lsws/modules/mod_lscache.so') || @file_exists('/usr/local/lsws/modules/lscache.so');
        if ($info['config']['lscache'] ?? false) {
            echo '<span style="color:#46b450">✅ Aktyvus</span>';
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
            echo '<span style="color:#d63638">❌ Nerastas</span>';
        }
        echo '</td></tr>';

        if (!empty($info['config']['lscache_storage_path'])) {
            echo '<tr><td><strong>Talpyklos katalogas</strong></td><td><code>' . esc_html($info['config']['lscache_storage_path']) . '</code></td></tr>';
        }

        $lscwpActive = defined('LSCWP_V') || class_exists('LiteSpeed\Core');
        echo '<tr><td><strong>LSCWP įskiepis</strong></td><td>';
        if ($lscwpActive) {
            echo '<span style="color:#dba617">⚠ Aktyvus — gali konfliktuoti su šio įskiepio talpyklos valdymu</span>';
        } else {
            echo '<span style="color:#46b450">✅ Neaktyvus — šis įskiepis valdo talpyklą</span>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        // ── Cache settings ────────────────────────────────────────────────────
        echo '<h2>Talpyklos nustatymai</h2>';
        echo '<p style="color:#666">Šis įskiepis siunčia <code>X-LiteSpeed-Cache-Control</code> antraštes — tą patį mechanizmą naudoja LSCWP. Nereikia jokio papildomo įskiepio.</p>';

        echo '<form method="post">';
        wp_nonce_field('vlt_ls_settings');
        echo '<table class="form-table">';

        self::row(
            'Įjungti LSCache talpyklą',
            '<input type="checkbox" name="vlt_ls_cache_enabled" value="1"' . checked($enabled, true, false) . '>',
            'Siunčia <code>X-LiteSpeed-Cache-Control: public,max-age=N</code> antraštę. LiteSpeed/OLS išsaugo puslapį talpykloje.'
        );
        self::row(
            'Talpyklos gyvavimo laikas (TTL)',
            '<input type="number" name="vlt_ls_cache_ttl" value="' . esc_attr($ttl) . '" min="60" style="width:100px"> sekundžių',
            'Kiek laiko puslapis laikomas talpykloje. Rekomenduojama: 86400 (1 diena). Po turinio keitimo talpykla išvaloma automatiškai.'
        );
        self::row(
            'Talpykluoti prisijungusius vartotojus',
            '<input type="checkbox" name="vlt_ls_cache_logged_in" value="1"' . checked(get_option('vlt_ls_cache_logged_in', false), true, false) . '>',
            'Paprastai išjungta — prisijungę vartotojai mato personalizuotą turinį. Įjunkite tik jei turinys visiems vienodas.'
        );
        self::row(
            'Talpykluoti paieškos rezultatus',
            '<input type="checkbox" name="vlt_ls_cache_search" value="1"' . checked(get_option('vlt_ls_cache_search', false), true, false) . '>',
            'Paieška generuoja daug unikalių URL. Rekomenduojama išjungti didelėse svetainėse.'
        );
        self::row(
            'Talpykluoti 404 puslapius',
            '<input type="checkbox" name="vlt_ls_cache_404" value="1"' . checked(get_option('vlt_ls_cache_404', false), true, false) . '>',
            '404 puslapiai paprastai nekintantys — talpykla sumažina serverio apkrovą.'
        );

        echo '</table>';
        echo '<p class="submit"><button class="button button-primary" type="submit">Išsaugoti</button></p>';
        echo '</form>';

        // ── Purge actions ─────────────────────────────────────────────────────
        echo '<h2>Talpyklos valdymas</h2><p>';
        echo Plugin::purgeButton('litespeed', 'Valyti LiteSpeed talpyklą');
        echo Plugin::purgeButton('all', 'Valyti viską');
        echo '</p>';

        // ── Config file dump ──────────────────────────────────────────────────
        $configFiles = array_unique(array_filter([
            $info['config']['config_file'] ?? '',
            '/etc/openlitespeed/httpd_config.conf',
            '/etc/openlitespeed/httpd-vhosts.conf',
            '/usr/local/lsws/conf/httpd_config.conf',
            '/usr/local/directadmin/data/templates/openlitespeed_vhost.conf',
            '/usr/local/directadmin/data/templates/custom/openlitespeed_vhost.conf',
        ]));

        $shown = [];
        foreach ($configFiles as $configFile) {
            if (!$configFile || isset($shown[$configFile]) || !@is_readable($configFile)) {
                continue;
            }
            $shown[$configFile] = true;
            echo '<h2>Konfigūracija: <code style="font-size:13px">' . esc_html($configFile) . '</code></h2>';
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:400px;font-size:12px">';
            echo esc_html(substr(@file_get_contents($configFile) ?: '(nepasiekiamas)', 0, 50000));
            echo '</pre>';
        }

        foreach (['/usr/local/lsws/conf/vhosts/', '/etc/openlitespeed/vhosts/'] as $dir) {
            foreach (@glob($dir . '*/vhconf.conf') ?: [] as $vhconf) {
                if (!@is_readable($vhconf)) continue;
                echo '<h2>VHost: <code style="font-size:13px">' . esc_html($vhconf) . '</code></h2>';
                echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:300px;font-size:12px">';
                echo esc_html(substr(@file_get_contents($vhconf) ?: '', 0, 30000));
                echo '</pre>';
            }
        }

        echo '</div>';
    }

    private static function row(string $label, string $input, string $desc): void
    {
        echo '<tr><th style="width:250px">' . esc_html($label) . '</th><td>';
        echo '<label>' . $input . '</label>';
        echo '<p class="description">' . $desc . '</p>';
        echo '</td></tr>';
    }
}
