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
        echo '<table class="widefat fixed striped" style="max-width:700px;margin-bottom:16px"><tbody>';
        echo '<tr><td><strong>GC būsena</strong></td><td>' . (gc_enabled() ? '<span style="color:#46b450">✅ Įjungtas</span>' : '<span style="color:#d63638">❌ Išjungtas</span>') . '</td></tr>';
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
                echo '<div class="notice notice-' . $color . ' inline" style="margin:4px 0"><p>';
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
            echo '<span id="vlt-gc-fix-status" style="margin-left:8px;color:#666"></span></p>';
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

        echo '<table class="widefat fixed striped" style="max-width:700px;margin-bottom:16px"><tbody>';
        echo '<tr><td><strong>Redis</strong></td><td>' . ($qStatus['redis'] ? '<span style="color:#46b450">✅ Prijungtas</span>' : '<span style="color:#d63638">❌ Neprijungtas</span>') . '</td></tr>';
        echo '<tr><td><strong>Eilėje laukia</strong></td><td>' . $qStatus['queue_length'] . ' darbų</td></tr>';
        echo '<tr><td><strong>Vykdoma</strong></td><td>' . ($qStatus['running'] ? '<span style="color:#dba617">⚙ Taip</span>' : 'Ne') . '</td></tr>';
        echo '<tr><td><strong>PHP Fibers</strong></td><td>' . (class_exists('Fiber') ? '<span style="color:#46b450">✅ PHP ' . PHP_VERSION . '</span>' : '<span style="color:#d63638">❌ Reikia PHP 8.1+</span>') . '</td></tr>';
        echo '</tbody></table>';

        // Cron offload settings
        echo '<form method="post">';
        wp_nonce_field('vlt_perf_settings');
        echo '<table class="form-table" style="max-width:700px">';
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
        echo '<span id="vlt-queue-status" style="margin-left:8px;color:#666"></span>';
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

        // ── WP Cron status ────────────────────────────────────────────────────
        echo '<h2>WP-Cron užduotys</h2>';
        $crons = _get_cron_array() ?: [];
        $total = 0;
        $overdue = 0;
        $now = time();
        foreach ($crons as $ts => $hooks) {
            foreach ($hooks as $hook => $jobs) {
                $total += count($jobs);
                if ($ts < $now) {
                    $overdue += count($jobs);
                }
            }
        }
        echo '<p>Iš viso: <strong>' . $total . '</strong> užduočių | ';
        echo 'Vėluoja: <strong style="color:' . ($overdue > 0 ? '#d63638' : '#46b450') . '">' . $overdue . '</strong></p>';

        if ($total > 0) {
            echo '<table class="widefat fixed striped" style="max-width:900px"><thead><tr><th>Hook</th><th>Kitas paleidimas</th><th>Intervalas</th><th>Argumentai</th></tr></thead><tbody>';
            foreach ($crons as $ts => $hooks) {
                foreach ($hooks as $hook => $jobs) {
                    foreach ($jobs as $job) {
                        $diff = $ts - $now;
                        $timeStr = $diff < 0
                            ? '<span style="color:#d63638">Vėluoja ' . human_time_diff($ts, $now) . '</span>'
                            : 'Po ' . human_time_diff($now, $ts);
                        echo '<tr>';
                        echo '<td><code>' . esc_html($hook) . '</code></td>';
                        echo '<td>' . $timeStr . '</td>';
                        echo '<td>' . esc_html($job['schedule'] ?? 'vienkartinis') . '</td>';
                        echo '<td><small>' . esc_html(substr(json_encode($job['args'] ?? []), 0, 80)) . '</small></td>';
                        echo '</tr>';
                    }
                }
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
