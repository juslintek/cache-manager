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

        <!-- Purge buttons -->
        <p>
            <button id="vlt-purge-all" class="button button-primary">🗑 Valyti viską</button>
            <?php foreach ($types as $type): ?>
            <button class="button vlt-purge-one" data-type="<?php echo esc_attr($type); ?>" style="margin-left:4px">
                Valyti <?php echo esc_html($type); ?>
            </button>
            <?php endforeach; ?>
        </p>

        <!-- SSE popup overlay -->
        <div id="vlt-purge-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:none;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:8px;padding:28px 32px;min-width:380px;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,.3)">
                <h3 style="margin:0 0 16px;font-size:16px">🗑 Valoma talpykla…</h3>
                <!-- Progress bar -->
                <div style="background:#e0e0e0;border-radius:4px;height:12px;overflow:hidden;margin-bottom:10px">
                    <div id="vlt-purge-bar" style="background:#2271b1;height:100%;width:0;transition:width .4s ease;border-radius:4px"></div>
                </div>
                <div id="vlt-purge-pct" style="font-size:12px;color:#666;margin-bottom:12px">0%</div>
                <!-- Rolling log -->
                <div id="vlt-purge-items" style="font-family:monospace;font-size:11px;max-height:140px;overflow-y:auto;background:#f9f9f9;border:1px solid #e0e0e0;padding:8px;border-radius:4px"></div>
                <div id="vlt-purge-done-msg" style="display:none;margin-top:12px;color:#46b450;font-weight:600">✅ Viskas išvalyta!</div>
            </div>
        </div>

        <!-- Purge history -->
        <h3 style="margin-top:20px">Valymo žurnalas</h3>
        <div id="vlt-purge-history" style="font-size:11px;font-family:monospace;max-height:180px;overflow-y:auto;background:#f9f9f9;border:1px solid #ddd;padding:8px;border-radius:4px">
            <em style="color:#999">Kraunama…</em>
        </div>

        <script>
        (function() {
            const restUrl = '<?php echo $restUrl; ?>';
            const nonce   = '<?php echo $nonce; ?>';

            function loadHistory() {
                fetch(restUrl + '/purge-log', {headers: {'X-WP-Nonce': nonce}})
                .then(r => r.json()).then(entries => {
                    const el = document.getElementById('vlt-purge-history');
                    if (!entries || !entries.length) { el.innerHTML = '<em style="color:#999">Nėra įrašų</em>'; return; }
                    el.innerHTML = entries.map(e => {
                        const d = new Date((e.ts||0) * 1000).toLocaleString();
                        return '<div><span style="color:#888">' + d + '</span> <strong style="color:#2271b1">' + (e.type||'?') + '</strong>'
                            + (e.ms ? ' <span style="color:#666">' + e.ms + 'ms</span>' : '')
                            + ' <span style="color:#aaa">(' + (e.user||'?') + ')</span></div>';
                    }).join('');
                });
            }

            function startPurge(types) {
                const overlay = document.getElementById('vlt-purge-overlay');
                const bar     = document.getElementById('vlt-purge-bar');
                const pct     = document.getElementById('vlt-purge-pct');
                const items   = document.getElementById('vlt-purge-items');
                const doneMsg = document.getElementById('vlt-purge-done-msg');

                // Reset
                bar.style.width = '0'; bar.style.background = '#2271b1';
                pct.textContent = '0%'; items.innerHTML = ''; doneMsg.style.display = 'none';
                overlay.style.display = 'flex';

                const typeParam = types ? '?types=' + encodeURIComponent(types.join(',')) : '';
                const es = new EventSource(restUrl + '/purge-stream' + typeParam + (typeParam ? '&' : '?') + '_wpnonce=' + nonce);

                es.onmessage = function(ev) {
                    try {
                        const d = JSON.parse(ev.data);
                        if (d.event === 'progress') {
                            bar.style.width = d.pct + '%';
                            pct.textContent = d.pct + '% — ' + d.type;
                            const line = document.createElement('div');
                            line.innerHTML = '<span style="color:#46b450">✓</span> <strong>' + d.type + '</strong>'
                                + (d.ms ? ' <span style="color:#888">' + d.ms + 'ms</span>' : '');
                            items.appendChild(line);
                            items.scrollTop = items.scrollHeight;
                        } else if (d.event === 'done') {
                            bar.style.width = '100%'; bar.style.background = '#46b450';
                            pct.textContent = '100%';
                            doneMsg.style.display = 'block';
                            es.close();
                            setTimeout(() => {
                                overlay.style.display = 'none';
                                loadHistory();
                            }, 1800);
                        }
                    } catch(e) {}
                };
                es.onerror = function() { es.close(); overlay.style.display = 'none'; };
            }

            document.getElementById('vlt-purge-all').addEventListener('click', function() {
                startPurge(null);
            });
            document.querySelectorAll('.vlt-purge-one').forEach(btn => {
                btn.addEventListener('click', function() {
                    startPurge([this.dataset.type]);
                });
            });

            loadHistory();
        })();
        </script>

        <?php
        echo '</div>';
    }
}
