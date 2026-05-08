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

        // System stats
        $ram_total = 0; $ram_used = 0;
        if (is_readable('/proc/meminfo')) {
            $mem = [];
            foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (int)$m[2];
            }
            $ram_total = ($mem['MemTotal'] ?? 0) / 1024;
            $ram_used  = $ram_total - ($mem['MemAvailable'] ?? 0) / 1024;
        }
        $cpu_idle = 100.0;
        if (is_readable('/proc/stat')) {
            $stat = explode(' ', trim(file('/proc/stat')[0]));
            array_shift($stat);
            $total = array_sum($stat);
            $idle  = (int)($stat[3] ?? 0);
            $cpu_idle = $total > 0 ? round($idle / $total * 100, 1) : 100;
        }
        $cpu_used = round(100 - $cpu_idle, 1);

        Plugin::notice();
        $types   = $p->purge()->types();
        $restUrl = esc_js(rest_url('vlt-cache/v1'));
        $nonce   = wp_create_nonce('wp_rest');
        ?>
        <div class="wrap">
        <h1 class="mb-4">Podėlio Valdymas — Suvestinė</h1>

        <!-- Cache status cards -->
        <div class="grid gap-3 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">

        <?php
        // Helper: render a card
        $card = function(string $icon, string $title, string $value, string $sub, float $pct, string $color, string $action = '') {
            $bar = '<div class="h-1 bg-gray-200 rounded mt-2"><div style="height:4px;background:' . $color . ';width:' . min(100, $pct) . '%;border-radius:2px;transition:width .5s"></div></div>';
            echo '<div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">';
            echo '<div class="flex justify-between items-start">';
            echo '<div><div class="text-[11px] text-gray-400 uppercase tracking-wide">' . esc_html($title) . '</div>';
            echo '<div class="text-xl font-bold mt-0.5 text-gray-900">' . $value . '</div>';
            echo '<div class="text-[11px] text-gray-500 mt-0.5">' . $sub . '</div></div>';
            echo '<span class="text-2xl opacity-70">' . $icon . '</span></div>';
            echo $bar;
            echo '</div>';
        };

        // Redis
        if ($redis['connected']) {
            $r = \VLT\CacheManager\Redis\RedisFactory::create(0.5);
            $redisMemUsed = 0; $redisMemMax = 0;
            if ($r) {
                $info = $r->info('memory');
                $redisMemUsed = (int)($info['used_memory'] ?? 0);
                $redisMemMax  = (int)($info['maxmemory'] ?? 0);
                $r->close();
            }
            $redisPct = $redisMemMax > 0 ? $redisMemUsed / $redisMemMax * 100 : 20;
            $card('🔴', 'Redis', $redis['memory'], $redis['keys'] . ' raktų', $redisPct, '#dc3232');
        } else {
            $card('🔴', 'Redis', 'Neprijungtas', '—', 0, '#ccc');
        }

        // OPcache
        if ($opcache) {
            $oc_used  = $opcache['memory_usage']['used_memory'] ?? 0;
            $oc_free  = $opcache['memory_usage']['free_memory'] ?? 1;
            $oc_pct   = round($oc_used / ($oc_used + $oc_free) * 100, 1);
            $oc_hit   = round($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
            $oc_files = $opcache['opcache_statistics']['num_cached_scripts'] ?? 0;
            $card('⚡', 'OPcache', Plugin::formatSize($oc_used), $oc_files . ' failų · ' . $oc_hit . '% hit', $oc_pct, '#f0b849');
        }

        // LiteSpeed / Nginx cache
        $serverInfo = \VLT\CacheManager\ServerDetector::detect();
        $isLS = \VLT\CacheManager\ServerDetector::isLiteSpeed();
        if ($isLS) {
            $lsCacheDir = $serverInfo['cacheDir'] ?? '/usr/local/lsws/cachedata';
            $lsSize = Plugin::dirSize($lsCacheDir);
            $lsFiles = 0;
            if (is_dir($lsCacheDir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lsCacheDir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) { if ($f->isFile() && !str_starts_with($f->getFilename(), '.')) $lsFiles++; }
            }
            $card('🚀', 'LiteSpeed', Plugin::formatSize($lsSize), $lsFiles . ' failų', min(100, $lsSize / 1048576 / 10), '#2271b1');
        } elseif ($nginx_size > 0 || is_dir(VLT_CM_NGINX_CACHE)) {
            $card('🌐', 'Nginx FastCGI', Plugin::formatSize($nginx_size), 'Talpykla', min(100, $nginx_size / 1048576 / 10), '#2271b1');
        }

        // Elementor
        $card('🎨', 'Elementor CSS', $el_count . ' failų', 'CSS talpykla', min(100, $el_count / 2), '#9b59b6');

        // RAM
        if ($ram_total > 0) {
            $ram_pct = round($ram_used / $ram_total * 100, 1);
            $ramColor = $ram_pct > 85 ? '#dc3232' : ($ram_pct > 65 ? '#f0b849' : '#46b450');
            $card('💾', 'RAM', Plugin::formatSize((int)($ram_used * 1048576)), round($ram_used, 0) . ' / ' . round($ram_total, 0) . ' MB', $ram_pct, $ramColor);
        }

        // CPU
        $cpuColor = $cpu_used > 80 ? '#dc3232' : ($cpu_used > 50 ? '#f0b849' : '#46b450');
        $card('🖥', 'CPU', $cpu_used . '%', 'Naudojama', $cpu_used, $cpuColor);

        // Hit rate
        $hitColor = $ratio > 80 ? '#46b450' : ($ratio > 50 ? '#f0b849' : '#dc3232');
        $card('📊', 'Hit Rate', $ratio . '%', $stats['requests'] . ' užklausų šiandien', $ratio, $hitColor);
        ?>
        </div>

        <!-- Purge section with SSE progress -->
        <h2 class="mb-2">Greitas valymas</h2>
        <p class="mb-3">
            <button id="vlt-purge-all" class="button button-primary" class="!h-[34px] !px-4">
                🗑 Valyti viską
            </button>
            <?php foreach ($types as $type): ?>
            <button class="button vlt-purge-one" data-type="<?php echo esc_attr($type); ?>" class="!ml-1 !h-[34px]">
                <?php echo esc_html(ucfirst($type)); ?>
            </button>
            <?php endforeach; ?>
        </p>

        <!-- SSE popup overlay -->
        <div id="vlt-purge-overlay" class="hidden fixed inset-0 bg-black/55 z-[99999] items-center justify-center">
            <div class="bg-white rounded-xl p-8 min-w-[400px] max-w-[520px] shadow-2xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="m-0 text-base font-semibold">🗑 Valoma talpykla</h3>
                    <span id="vlt-purge-pct-label" class="text-sm text-gray-500 font-semibold">0%</span>
                </div>
                <div class="bg-gray-200 rounded-md h-2.5 overflow-hidden mb-3.5">
                    <div id="vlt-purge-bar" style="background:#2271b1;height:100%;width:0;transition:width .4s ease;border-radius:6px"></div>
                </div>
                <div id="vlt-purge-items" class="font-mono text-[11px] max-h-[150px] overflow-y-auto bg-gray-50 border border-gray-200 px-2.5 py-2 rounded-md leading-relaxed"></div>
                <div id="vlt-purge-done-msg" class="hidden mt-3.5 text-green-600 font-bold text-sm text-center">✅ Viskas išvalyta!</div>
            </div>
        </div>

        <!-- Purge history -->
        <h3 class="mt-5 mb-2">Valymo žurnalas</h3>
        <div id="vlt-purge-history" class="text-[11px] font-mono max-h-[200px] overflow-y-auto bg-gray-50 border border-gray-200 px-2.5 py-2 rounded-md leading-relaxed">
            <em style="color:#aaa">Kraunama…</em>
        </div>

        <?php
        // Recent purge events
        $entries = $p->logger()->readLog(gmdate('Y-m-d'));
        $purges  = array_filter($entries, fn($e) => ($e['type'] ?? '') === 'purge');
        $purges  = array_reverse($purges);
        $groups  = [];
        foreach ($purges as $pg) {
            $key = substr($pg['timestamp'] ?? '', 0, 19) . '|' . ($pg['user_id'] ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = ['timestamp' => $pg['timestamp'], 'user_name' => $pg['user_name'] ?? 'Sistema', 'user_id' => $pg['user_id'] ?? 0, 'ip' => $pg['ip'] ?? '', 'types' => []];
            }
            $groups[$key]['types'][] = is_array($pg['details']) ? implode(', ', $pg['details']) : $pg['details'];
        }
        $groups = array_slice($groups, 0, 10);

        if ($groups) {
            echo '<h2 style="margin-top:24px">Paskutiniai valymo įvykiai</h2>';
            echo '<style>.vlt-purge-group{cursor:pointer;user-select:none}.vlt-purge-group.open+.vlt-purge-detail{display:table-row}</style>';
            echo '<table class="widefat fixed striped" style="max-width:700px"><thead><tr><th>Laikas</th><th>Kas valė</th><th>Kas išvalyta</th></tr></thead><tbody>';
            foreach ($groups as $g) {
                $types_str  = implode(', ', $g['types']);
                $user_label = esc_html($g['user_name']);
                if ($g['user_id']) $user_label .= ' (ID:' . $g['user_id'] . ')';
                echo '<tr class="vlt-purge-group" onclick="this.classList.toggle(\'open\')">';
                echo '<td>▸ ' . esc_html($g['timestamp']) . '</td>';
                echo '<td>' . $user_label . '</td>';
                echo '<td>' . esc_html($types_str) . '</td></tr>';
                echo '<tr class="vlt-purge-detail" style="display:none"><td colspan="3"><strong>IP:</strong> ' . esc_html($g['ip']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        ?>

        <script>
        (function() {
            const restUrl = '<?php echo $restUrl; ?>';
            const nonce   = '<?php echo $nonce; ?>';

            function loadHistory() {
                fetch(restUrl + '/purge-log', {headers: {'X-WP-Nonce': nonce}})
                .then(r => r.json()).then(entries => {
                    const el = document.getElementById('vlt-purge-history');
                    if (!entries || !entries.length) { el.innerHTML = '<em style="color:#aaa">Nėra įrašų</em>'; return; }
                    el.innerHTML = entries.map(e => {
                        const d = new Date((e.ts||0)*1000).toLocaleString();
                        const ms = e.ms ? ' <span style="color:#888">'+e.ms+'ms</span>' : '';
                        return '<div><span style="color:#aaa">'+d+'</span> <strong style="color:#2271b1">'+e.type+'</strong>'+ms+' <span style="color:#bbb">('+e.user+')</span></div>';
                    }).join('');
                }).catch(()=>{});
            }

            function startPurge(types) {
                const overlay = document.getElementById('vlt-purge-overlay');
                const bar     = document.getElementById('vlt-purge-bar');
                const pct     = document.getElementById('vlt-purge-pct-label');
                const items   = document.getElementById('vlt-purge-items');
                const doneMsg = document.getElementById('vlt-purge-done-msg');
                bar.style.width='0'; bar.style.background='#2271b1';
                pct.textContent='0%'; items.innerHTML=''; doneMsg.style.display='none';
                overlay.style.display='flex';

                const param = types ? '?types='+encodeURIComponent(types.join(','))+'&' : '?';
                const es = new EventSource(restUrl+'/purge-stream'+param+'_wpnonce='+nonce);
                es.onmessage = ev => {
                    try {
                        const d = JSON.parse(ev.data);
                        if (d.event==='progress') {
                            bar.style.width=d.pct+'%';
                            pct.textContent=d.pct+'%';
                            const line=document.createElement('div');
                            line.innerHTML='<span style="color:#46b450">✓</span> <strong>'+d.type+'</strong>'+(d.ms?' <span style="color:#888">'+d.ms+'ms</span>':'');
                            items.appendChild(line); items.scrollTop=items.scrollHeight;
                        } else if (d.event==='done') {
                            bar.style.width='100%'; bar.style.background='#46b450';
                            pct.textContent='100%'; doneMsg.style.display='block';
                            es.close();
                            setTimeout(()=>{ overlay.style.display='none'; loadHistory(); }, 1800);
                        }
                    } catch(e){}
                };
                es.onerror=()=>{ es.close(); overlay.style.display='none'; };
            }

            document.getElementById('vlt-purge-all').addEventListener('click', ()=>startPurge(null));
            document.querySelectorAll('.vlt-purge-one').forEach(b=>b.addEventListener('click',function(){ startPurge([this.dataset.type]); }));
            loadHistory();
        })();
        </script>
        </div>
        <?php
    }
}
