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
        echo '<tr><td><strong>LSCache modulis</strong></td><td>' . (($info['config']['lscache'] ?? false) ? '<span style="color:#46b450">✅ Įjungtas</span>' : '<span style="color:#d63638">❌ Išjungtas</span>') . '</td></tr>';
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

        // Config file preview
        $configFile = $info['config']['config_file'] ?? '';
        if ($configFile && @is_readable($configFile)) {
            echo '<h2>Konfigūracijos failas</h2>';
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:400px;font-size:12px">';
            echo esc_html(substr(@file_get_contents($configFile) ?: '', 0, 50000));
            echo '</pre>';
        }

        echo '</div>';
    }
}
