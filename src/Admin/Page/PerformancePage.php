<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Async\AsyncQueue;
use VLT\CacheManager\Performance\GCAnalyzer;
use VLT\CacheManager\Plugin;

final class PerformancePage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-performance'; }
    public function title(): string { return 'Našumas'; }

    public function render(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('vlt_perf_settings')) {
            update_option('vlt_async_offload_cron', isset($_POST['vlt_async_offload_cron']));
            echo '<div class="notice notice-success"><p>Nustatymai išsaugoti.</p></div>';
        }

        Plugin::notice();
        $restUrl = esc_js(rest_url('vlt-cache/v1'));
        $nonce   = wp_create_nonce('wp_rest');

        echo '<div class="wrap"><h1>Podėlio Valdymas — Našumas</h1>';

        // ── GC Analysis ───────────────────────────────────────────────────────
        echo '<h2>PHP GC ir atminties analizė</h2>';
        $gc = GCAnalyzer::analyze();

        // Stats bar
        $stats = $gc['stats'];
        echo '<table class="widefat fixed striped" class="max-w-2xl mb-4"><tbody>';
        echo '<tr><td><strong>GC būsena</strong></td><td>' . (gc_enabled() ? '<span class="text-green-600">✅ Įjungtas</span>' : '<span class="text-red-600">❌ Išjungtas</span>') . '</td></tr>';
        echo '<tr><td><strong>GC paleidimų</strong></td><td>' . number_format($stats['runs']) . '</td></tr>';
        echo '<tr><td><strong>Surinkta ciklų</strong></td><td>' . number_format($stats['collected']) . '</td></tr>';
        echo '<tr><td><strong>Šaknų buferis</strong></td><td>' . number_format($stats['roots']) . ' / ' . number_format($stats['threshold']) . ' (slenkstis)</td></tr>';
        echo '<tr><td><strong>Atminties naudojimas</strong></td><td>' . size_format($stats['memory_used']) . ' / ' . esc_html($stats['memory_limit']) . '</td></tr>';
        echo '<tr><td><strong>Atminties pikas</strong></td><td>' . size_format($stats['memory_peak']) . '</td></tr>';
        echo '</tbody></table>';

        // Issues
        if (empty($gc['issues'])) {
            echo '<div class="notice notice-success inline"><p>✅ Nėra GC/atminties problemų.</p></div>';
        } else {
            foreach ($gc['issues'] as $issue) {
                $color = match ($issue['severity']) {
                    'critical' => 'error',
                    'warning'  => 'warning',
                    default    => 'info',
                };
                echo '<div class="notice notice-' . $color . ' inline" class="my-1"><p>';
                echo '<strong>' . esc_html($issue['title']) . '</strong> — ' . esc_html($issue['detail']);
                if (($issue['fix'] ?? '') === 'suggest' && !empty($issue['suggestion'])) {
                    echo '<br><em>' . esc_html($issue['suggestion']) . '</em>';
                }
                echo '</p></div>';
            }
        }

        // Auto-fix button
        $autoFixable = array_filter($gc['issues'], fn($i) => ($i['fix'] ?? '') === 'auto');
        if ($autoFixable) {
            echo '<p><button type="button" id="vlt-gc-fix" class="button button-primary">🔧 Automatiškai taisyti (' . count($autoFixable) . ')</button> ';
            echo '<span id="vlt-gc-fix-status" class="ml-2 text-gray-500"></span></p>';
            echo '<script>
            document.getElementById("vlt-gc-fix").addEventListener("click", function() {
                const s = document.getElementById("vlt-gc-fix-status");
                this.disabled = true; s.textContent = "Taisoma...";
                fetch("' . $restUrl . '/gc-fix", {method:"POST",headers:{"X-WP-Nonce":"' . $nonce . '"}})
                .then(r=>r.json()).then(d=>{
                    s.textContent = "✅ " + (d.applied||[]).join(", ");
                    s.style.color = "#46b450";
                }).catch(()=>{s.textContent="❌ Klaida";this.disabled=false;});
            });
            </script>';
        }

        // ── Async Queue ───────────────────────────────────────────────────────
        echo '<h2>Asinchroninė eilė (Redis)</h2>';
        $qStatus = AsyncQueue::status();

        echo '<table class="widefat fixed striped" class="max-w-2xl mb-4"><tbody>';
        echo '<tr><td><strong>Redis</strong></td><td>' . ($qStatus['redis'] ? '<span class="text-green-600">✅ Prijungtas</span>' : '<span class="text-red-600">❌ Neprijungtas</span>') . '</td></tr>';
        echo '<tr><td><strong>Eilėje laukia</strong></td><td>' . $qStatus['queue_length'] . ' darbų</td></tr>';
        echo '<tr><td><strong>Vykdoma</strong></td><td>' . ($qStatus['running'] ? '<span class="text-yellow-600">⚙ Taip</span>' : 'Ne') . '</td></tr>';
        echo '<tr><td><strong>PHP Fibers</strong></td><td>' . (class_exists('Fiber') ? '<span class="text-green-600">✅ PHP ' . PHP_VERSION . '</span>' : '<span class="text-red-600">❌ Reikia PHP 8.1+</span>') . '</td></tr>';
        echo '</tbody></table>';

        // Cron offload settings
        echo '<form method="post">';
        wp_nonce_field('vlt_perf_settings');
        echo '<table class="form-table" class="max-w-2xl">';
        echo '<tr><th>WP-Cron → Redis eilė</th><td>';
        echo '<label><input type="checkbox" name="vlt_async_offload_cron" value="1"' . checked(get_option('vlt_async_offload_cron'), true, false) . '> Įjungti</label>';
        echo '<p class="description">WP-Cron užduotys bus vykdomos per Redis eilę, o ne sinchroniškai su HTTP užklausa. Sumažina puslapio įkėlimo laiką. Reikia Redis.</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '<p class="submit"><button class="button button-primary" type="submit">Išsaugoti</button></p>';
        echo '</form>';

        // Manual queue trigger
        echo '<p>';
        echo '<button type="button" id="vlt-queue-run" class="button">▶ Vykdyti eilę dabar</button> ';
        echo '<span id="vlt-queue-status" class="ml-2 text-gray-500"></span>';
        echo '</p>';
        echo '<script>
        document.getElementById("vlt-queue-run").addEventListener("click", function() {
            const s = document.getElementById("vlt-queue-status");
            this.disabled = true; s.textContent = "Vykdoma...";
            fetch("' . $restUrl . '/queue-run", {method:"POST",headers:{"X-WP-Nonce":"' . $nonce . '"}})
            .then(r=>r.json()).then(d=>{
                s.textContent = "✅ Apdorota: " + (d.processed||0) + " darbų";
                s.style.color = "#46b450";
                this.disabled = false;
            }).catch(()=>{s.textContent="❌ Klaida";this.disabled=false;});
        });
        </script>';

        // ── WP Cron live monitor ──────────────────────────────────────────────
        echo '<h2>WP-Cron — Gyvas stebėjimas</h2>';

        // Scheduled jobs summary
        $crons   = _get_cron_array() ?: [];
        $total   = 0;
        $overdue = 0;
        $now     = time();
        $scheduled = [];
        foreach ($crons as $ts => $hooks) {
            foreach ($hooks as $hook => $jobs) {
                foreach ($jobs as $job) {
                    $total++;
                    if ($ts < $now) $overdue++;
                    $scheduled[] = ['hook' => $hook, 'ts' => $ts, 'schedule' => $job['schedule'] ?? 'once', 'args' => $job['args'] ?? []];
                }
            }
        }
        usort($scheduled, fn($a, $b) => $a['ts'] <=> $b['ts']);

        echo '<p>Suplanuota: <strong>' . $total . '</strong> | Vėluoja: <strong style="color:' . ($overdue > 0 ? '#d63638' : '#46b450') . '">' . $overdue . '</strong></p>';

        // Live execution log (SSE)
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">';

        // Left: live log
        echo '<div>';
        echo '<h3 style="margin:0 0 8px">Vykdymo žurnalas <span id="vlt-cron-live-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ccc;margin-left:4px"></span></h3>';
        echo '<div id="vlt-cron-log" class="h-[300px] overflow-y-auto bg-gray-900 text-gray-300 font-mono text-[11px] p-2 rounded"></div>';
        echo '</div>';

        // Right: per-hook stats
        echo '<div>';
        echo '<h3 style="margin:0 0 8px">Statistika pagal hook</h3>';
        echo '<div id="vlt-cron-stats" class="h-[300px] overflow-y-auto"></div>';
        echo '</div>';

        echo '</div>';

        // Scheduled table
        echo '<h3>Suplanuotos užduotys</h3>';
        echo '<table class="widefat fixed striped" class="max-w-4xl"><thead><tr><th>Hook</th><th>Kitas paleidimas</th><th>Intervalas</th></tr></thead><tbody id="vlt-cron-schedule">';
        foreach (array_slice($scheduled, 0, 30) as $job) {
            $diff    = $job['ts'] - $now;
            $timeStr = $diff < 0 ? '<span class="text-red-600">Vėluoja ' . human_time_diff($job['ts'], $now) . '</span>' : 'Po ' . human_time_diff($now, $job['ts']);
            echo '<tr id="cron-row-' . esc_attr($job['hook']) . '">';
            echo '<td><code>' . esc_html($job['hook']) . '</code></td>';
            echo '<td>' . $timeStr . '</td>';
            echo '<td>' . esc_html($job['schedule']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // SSE live update script
        $streamUrl = esc_js(rest_url('vlt-cache/v1/cron-stream'));
        $statsUrl  = esc_js(rest_url('vlt-cache/v1/cron-stats'));
        echo '<script>
        (function() {
            const log = document.getElementById("vlt-cron-log");
            const statsEl = document.getElementById("vlt-cron-stats");
            const dot = document.getElementById("vlt-cron-live-dot");
            const nonce = "' . $nonce . '";
            let lastTs = 0;

            function statusColor(s) {
                return {running:"#fbbf24",done:"#4ade80",error:"#f87171",queued:"#94a3b8"}[s]||"#d4d4d4";
            }

            function appendLog(entries) {
                entries.forEach(e => {
                    const line = document.createElement("div");
                    const t = new Date(e.ts * 1000).toLocaleTimeString();
                    const mem = (e.memory/1048576).toFixed(1) + "MB";
                    const dur = e.duration ? " " + (e.duration*1000).toFixed(0) + "ms" : "";
                    line.style.color = statusColor(e.status);
                    line.textContent = "[" + t + "] " + e.status.toUpperCase().padEnd(7) + " " + e.hook + dur + " (" + mem + ")";
                    if (e.error) { const err = document.createElement("div"); err.style.color="#f87171"; err.textContent = "  ↳ " + e.error; log.appendChild(err); }
                    log.insertBefore(line, log.firstChild);
                    // Highlight schedule row
                    const row = document.getElementById("cron-row-" + e.hook);
                    if (row) { row.style.background = e.status === "running" ? "#fef3c7" : e.status === "done" ? "#f0fdf4" : ""; }
                });
                if (log.children.length > 100) log.removeChild(log.lastChild);
            }

            function refreshStats() {
                fetch("' . $statsUrl . '", {headers:{"X-WP-Nonce":nonce}})
                .then(r=>r.json()).then(d=>{
                    if (!d.stats) return;
                    statsEl.innerHTML = "<table class=\'widefat fixed striped\' style=\'font-size:11px\'><thead><tr><th>Hook</th><th>Paleista</th><th>Klaidos</th><th>Vid. ms</th></tr></thead><tbody>"
                        + d.stats.map(s=>"<tr><td><code>"+s.hook+"</code></td><td>"+s.runs+"</td><td style=\'color:"+(s.errors>0?"#d63638":"inherit")+"\'>"+s.errors+"</td><td>"+s.avg_ms+"</td></tr>").join("")
                        + "</tbody></table>";
                    if (d.log) appendLog(d.log.filter(e=>e.ts>lastTs));
                });
            }

            // Initial load
            refreshStats();

            // SSE stream
            const es = new EventSource("' . $streamUrl . '?since=" + lastTs + "&_wpnonce=" + nonce);
            es.onopen = () => { dot.style.background = "#4ade80"; };
            es.onerror = () => { dot.style.background = "#f87171"; };
            es.onmessage = (ev) => {
                try {
                    const entries = JSON.parse(ev.data);
                    if (entries.length) {
                        appendLog(entries);
                        lastTs = Math.max(...entries.map(e=>e.ts));
                        refreshStats();
                    }
                } catch(e) {}
            };

            // Refresh stats every 10s even without SSE events
            setInterval(refreshStats, 10000);
        })();
        </script>';

        echo '</div>';
    }
}
