<?php

declare(strict_types=1);

namespace Gratis\Cache\Admin\Page;

defined('ABSPATH') || exit;
use Gratis\Cache\Admin\AdminPage;
use Gratis\Cache\Plugin;
use Gratis\Cache\ServerDetector;

final class ApachePage extends AdminPage {

    public function slug(): string {
 return 'vlt-cache-apache'; }
    public function title(): string {
 return 'Apache'; }

    public function render(): void {
        $info = ServerDetector::detect();
        Plugin::notice();
        echo '<div class="wrap"><h1>Podėlio Valdymas — Apache</h1>';

        echo '<table class="widefat fixed striped tw-max-w-2xl tw-my-5"><tbody>';
        echo '<tr><td><strong>Serveris</strong></td><td>Apache' . ($info['version'] ? ' v' . esc_html($info['version']) : '') . '</td></tr>';
        echo '<tr><td><strong>Konfigūracijos failas</strong></td><td><code>' . esc_html($info['config']['config_file'] ?? '—') . '</code></td></tr>';
        echo '<tr><td><strong>mod_cache</strong></td><td>' . (($info['config']['mod_cache'] ?? false) ? '<span class="tw-text-green-600">✅</span>' : '<span class="tw-text-red-600">❌</span>') . '</td></tr>';
        echo '<tr><td><strong>mod_deflate</strong></td><td>' . (($info['config']['mod_deflate'] ?? false) ? '<span class="tw-text-green-600">✅</span>' : '<span class="tw-text-red-600">❌</span>') . '</td></tr>';
        echo '<tr><td><strong>mod_expires</strong></td><td>' . (($info['config']['mod_expires'] ?? false) ? '<span class="tw-text-green-600">✅</span>' : '<span class="tw-text-red-600">❌</span>') . '</td></tr>';
        echo '</tbody></table>';

        if (!empty($info['recommendations'])) {
            echo '<h2>Rekomendacijos</h2><ul>';
            foreach ($info['recommendations'] as $rec) {
                echo '<li>⚠ ' . esc_html($rec) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Talpyklos valdymas</h2><p>';
        echo wp_kses_post(Plugin::purgeButton('opcache', 'Valyti OPcache'));
        echo wp_kses_post(Plugin::purgeButton('all', 'Valyti viską'));
        echo '</p>';

        $configFile = $info['config']['config_file'] ?? '';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Server config path outside WP root may hit open_basedir.
        if ($configFile && @is_readable($configFile)) {
            echo '<h2>Konfigūracijos failas</h2>';
            echo '<pre class="tw-bg-gray-900 tw-text-gray-300 tw-p-4 tw-overflow-auto tw-max-h-[400px] tw-text-xs tw-rounded">';
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local Apache config preview.
            echo esc_html(substr(@file_get_contents($configFile) ?: '', 0, 50000));
            echo '</pre>';
        }

        echo '</div>';
    }
}
