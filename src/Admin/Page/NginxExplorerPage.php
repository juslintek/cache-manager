<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Plugin;

final class NginxExplorerPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-nginx'; }
    public function title(): string { return 'Nginx naršyklė'; }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Podėlio Valdymas — Nginx naršyklė</h1>';

        if (!empty($_GET['preview']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_nginx_preview')) {
            $this->renderPreview();
            return;
        }

        $cache_dir = VLT_CM_NGINX_CACHE;
        if (!is_dir($cache_dir)) {
            echo '<p>Nginx talpyklos katalogas nerastas.</p></div>';
            return;
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cache_dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $path = $file->getPathname();
                $raw_start = file_get_contents($path, false, null, 0, 2048);
                $url = '—';
                if (preg_match('/^KEY:\s*(.+)$/m', $raw_start, $m)) {
                    $url = trim($m[1]);
                }
                $status = '—';
                if (preg_match('/^Status:\s*(\d+)/m', $raw_start, $m)) {
                    $status = $m[1];
                }
                $files[] = [
                    'path'   => $path,
                    'url'    => $url,
                    'size'   => $file->getSize(),
                    'mtime'  => $file->getMTime(),
                    'status' => $status,
                ];
            }
        }

        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        $total_size = array_sum(array_column($files, 'size'));
        echo '<p><strong>' . count($files) . '</strong> failų talpykloje, bendras dydis: <strong>' . esc_html(Plugin::formatSize($total_size)) . '</strong></p>';

        $per_page    = 50;
        $page_num    = max(1, (int) ($_GET['paged'] ?? 1));
        $total_pages = max(1, ceil(count($files) / $per_page));
        $files_page  = array_slice($files, ($page_num - 1) * $per_page, $per_page);

        echo '<table class="widefat fixed striped"><thead><tr><th>URL / Raktas</th><th style="width:80px">Statusas</th><th style="width:100px">Dydis</th><th style="width:160px">Talpykloje nuo</th><th style="width:120px">Veiksmai</th></tr></thead><tbody>';
        foreach ($files_page as $f) {
            $preview_url = wp_nonce_url(admin_url('admin.php?page=vlt-cache-nginx&preview=' . urlencode($f['path'])), 'vlt_nginx_preview');
            $url_display = $f['url'];
            if (strlen($url_display) > 80) {
                $url_display = substr($url_display, 0, 77) . '...';
            }
            echo '<tr>';
            echo '<td title="' . esc_attr($f['url']) . '"><code style="font-size:12px">' . esc_html($url_display) . '</code></td>';
            echo '<td>' . esc_html($f['status']) . '</td>';
            echo '<td>' . esc_html(Plugin::formatSize($f['size'])) . '</td>';
            echo '<td>' . esc_html(date('Y-m-d H:i:s', $f['mtime'])) . '</td>';
            echo '<td><a href="' . esc_url($preview_url . '&mode=source') . '">Kodas</a> | <a href="' . esc_url($preview_url . '&mode=render') . '">Peržiūra</a></td>';
            echo '</tr>';
        }
        if (!$files_page) {
            echo '<tr><td colspan="5">Talpykla tuščia.</td></tr>';
        }
        echo '</tbody></table>';

        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $page_num,
                'total'   => $total_pages,
            ]);
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function renderPreview(): void
    {
        $file = realpath(sanitize_text_field($_GET['preview']));
        if ($file && str_starts_with($file, VLT_CM_NGINX_CACHE) && is_file($file)) {
            $raw     = file_get_contents($file);
            $parts   = explode("\r\n\r\n", $raw, 2);
            $headers = $parts[0] ?? '';
            $body    = $parts[1] ?? $raw;
            $mode    = $_GET['mode'] ?? 'source';

            echo '<p><a href="' . esc_url(admin_url('admin.php?page=vlt-cache-nginx')) . '">← Atgal į sąrašą</a></p>';
            echo '<h2>Talpyklos failas</h2>';
            echo '<table class="widefat" style="max-width:700px;margin-bottom:20px"><tbody>';
            echo '<tr><td><strong>Failas</strong></td><td><code>' . esc_html(basename($file)) . '</code></td></tr>';
            echo '<tr><td><strong>Dydis</strong></td><td>' . esc_html(Plugin::formatSize(filesize($file))) . '</td></tr>';
            echo '<tr><td><strong>Sukurtas</strong></td><td>' . esc_html(date('Y-m-d H:i:s', filemtime($file))) . '</td></tr>';
            echo '</tbody></table>';

            if (preg_match('/^KEY:\s*(.+)$/m', $headers, $m)) {
                echo '<p><strong>URL raktas:</strong> <code>' . esc_html(trim($m[1])) . '</code></p>';
            }

            echo '<h3>Antraštės</h3><pre style="background:#f5f5f5;padding:10px;overflow:auto;max-height:200px">' . esc_html($headers) . '</pre>';

            $preview_url = wp_nonce_url(admin_url('admin.php?page=vlt-cache-nginx&preview=' . urlencode($file)), 'vlt_nginx_preview');
            echo '<p>';
            echo '<a href="' . esc_url($preview_url . '&mode=source') . '" class="button' . ($mode === 'source' ? ' button-primary' : '') . '">Šaltinio kodas</a> ';
            echo '<a href="' . esc_url($preview_url . '&mode=render') . '" class="button' . ($mode === 'render' ? ' button-primary' : '') . '">Atvaizdavimas</a>';
            echo '</p>';

            if ($mode === 'render') {
                echo '<iframe srcdoc="' . esc_attr($body) . '" style="width:100%;height:600px;border:1px solid #ccc;background:#fff" sandbox="allow-same-origin"></iframe>';
            } else {
                echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:15px;overflow:auto;max-height:600px;font-size:12px;line-height:1.4">' . esc_html(substr($body, 0, 200000)) . '</pre>';
                if (strlen($body) > 200000) {
                    echo '<p><em>Rodoma tik pirmi 200KB iš ' . esc_html(Plugin::formatSize(strlen($body))) . '</em></p>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Failas nerastas arba netinkamas kelias.</p></div>';
        }
        echo '</div>';
    }
}
