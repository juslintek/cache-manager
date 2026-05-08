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

        echo '<h2>Greitas valymas</h2>';

        $types   = $p->purge()->types();
        $restUrl = esc_js(rest_url('vlt-cache/v1'));
        $nonce   = wp_create_nonce('wp_rest');
        ?>
        <div id="vlt-purge-wrap">
            <button id="vlt-purge-all" class="button button-primary">🗑 Valyti viską</button>
            <?php foreach ($types as $type): ?>
            <button class="button vlt-purge-one" data-type="<?php echo esc_attr($type); ?>" style="margin-left:4px">
                Valyti <?php echo esc_html($type); ?>
            </button>
            <?php endforeach; ?>

            <div id="vlt-purge-progress" style="display:none;margin-top:12px;max-width:500px">
                <div style="background:#e0e0e0;border-radius:4px;height:20px;overflow:hidden">
                    <div id="vlt-purge-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s;border-radius:4px"></div>
                </div>
                <div id="vlt-purge-status" style="font-size:12px;margin-top:6px;color:#666"></div>
                <div id="vlt-purge-log" style="margin-top:8px;font-family:monospace;font-size:11px;max-height:120px;overflow-y:auto;background:#f9f9f9;border:1px solid #ddd;padding:6px;border-radius:4px"></div>
            </div>
        </div>

        <script>
        (function() {
            const restUrl = '<?php echo $restUrl; ?>';
            const nonce   = '<?php echo $nonce; ?>';
            const types   = <?php echo json_encode($types); ?>;

            async function purgeTypes(list) {
                const wrap = document.getElementById('vlt-purge-progress');
                const bar  = document.getElementById('vlt-purge-bar');
                const status = document.getElementById('vlt-purge-status');
                const log  = document.getElementById('vlt-purge-log');
                wrap.style.display = 'block';
                log.innerHTML = '';
                let done = 0;

                for (const type of list) {
                    status.textContent = 'Valoma: ' + type + '…';
                    bar.style.width = Math.round(done / list.length * 100) + '%';
                    try {
                        const r = await fetch(restUrl + '/purge/' + type, {
                            method: 'POST',
                            headers: {'X-WP-Nonce': nonce}
                        });
                        const d = await r.json();
                        const line = document.createElement('div');
                        line.style.color = d.ok ? '#46b450' : '#d63638';
                        line.textContent = '✓ ' + type + ' — ' + (d.ms || '?') + 'ms';
                        log.appendChild(line);
                        log.scrollTop = log.scrollHeight;
                    } catch(e) {
                        const line = document.createElement('div');
                        line.style.color = '#d63638';
                        line.textContent = '✗ ' + type + ' — klaida';
                        log.appendChild(line);
                    }
                    done++;
                    bar.style.width = Math.round(done / list.length * 100) + '%';
                }
                status.textContent = '✅ Išvalyta ' + done + ' talpyklų';
                bar.style.background = '#46b450';
            }

            document.getElementById('vlt-purge-all').addEventListener('click', function() {
                this.disabled = true;
                purgeTypes(types).finally(() => { this.disabled = false; });
            });

            document.querySelectorAll('.vlt-purge-one').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.disabled = true;
                    purgeTypes([this.dataset.type]).finally(() => { this.disabled = false; });
                });
            });
        })();
        </script>

        <!-- Purge history log -->
        <h3 style="margin-top:20px">Valymo istorija</h3>
        <div id="vlt-purge-history" style="font-size:11px;font-family:monospace;max-height:200px;overflow-y:auto;background:#f9f9f9;border:1px solid #ddd;padding:8px;border-radius:4px">
            <em style="color:#999">Kraunama…</em>
        </div>
        <script>
        fetch('<?php echo esc_js(rest_url('vlt-cache/v1/purge-log')); ?>', {
            headers: {'X-WP-Nonce': '<?php echo $nonce; ?>'}
        }).then(r => r.json()).then(entries => {
            const el = document.getElementById('vlt-purge-history');
            if (!entries.length) { el.innerHTML = '<em style="color:#999">Nėra įrašų</em>'; return; }
            el.innerHTML = entries.map(e => {
                const d = new Date(e.ts * 1000).toLocaleString();
                return '<div><span style="color:#666">' + d + '</span> <strong>' + e.type + '</strong> ' + e.ms + 'ms <span style="color:#999">(' + (e.user||'?') + ')</span></div>';
            }).join('');
        });
        </script>
        <?php
        echo '</div>';
    }
}
