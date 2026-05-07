<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Plugin;

final class SettingsPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-settings'; }
    public function title(): string { return 'Nustatymai'; }

    public function render(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('vlt_cm_settings')) {
            // Each option saved independently — unchecking one must not affect others
            update_option('vlt_cm_logging',    isset($_POST['vlt_cm_logging']));
            update_option('vlt_cm_cf_tracking', isset($_POST['vlt_cm_cf_tracking']));
            update_option('vlt_cm_log_days', max(1, (int) ($_POST['vlt_cm_log_days'] ?? 30)));
            if (!empty($_POST['vlt_cm_log_path'])) {
                update_option('vlt_cm_log_path', sanitize_text_field($_POST['vlt_cm_log_path']));
            }
            if (!empty($_POST['vlt_cm_trace_path'])) {
                update_option('vlt_cm_trace_path', sanitize_text_field($_POST['vlt_cm_trace_path']));
            }
            update_option('vlt_cm_log_max_mb', max(0, (int) ($_POST['vlt_cm_log_max_mb'] ?? 500)));
            update_option('vlt_cm_trace_max_mb', max(0, (int) ($_POST['vlt_cm_trace_max_mb'] ?? 200)));
            // Hook tracing
            update_option('vlt_trace_hooks', isset($_POST['vlt_trace_hooks']));
            update_option('vlt_trace_hook_threshold_ms', max(0.1, (float) ($_POST['vlt_trace_hook_threshold_ms'] ?? 1.0)));
            // Redis manual config
            update_option('vlt_redis_socket', sanitize_text_field($_POST['vlt_redis_socket'] ?? ''));
            update_option('vlt_redis_host', sanitize_text_field($_POST['vlt_redis_host'] ?? ''));
            update_option('vlt_redis_port', max(0, (int) ($_POST['vlt_redis_port'] ?? 0)));
            // LiteSpeed
            update_option('vlt_litespeed_purge', isset($_POST['vlt_litespeed_purge']));
            // Image optimization
            update_option('vlt_img_optm_enabled', isset($_POST['vlt_img_optm_enabled']));
            update_option('vlt_img_optm_serve_webp', isset($_POST['vlt_img_optm_serve_webp']));
            update_option('vlt_img_optm_quality', max(1, min(100, (int) ($_POST['vlt_img_optm_quality'] ?? 82))));
            echo '<div class="notice notice-success"><p>Nustatymai išsaugoti.</p></div>';
        }

        if (!empty($_GET['dropin_installed'])) {
            echo '<div class="notice notice-success"><p>Object-cache.php drop-in sėkmingai įdiegtas.</p></div>';
        }

        if (!empty($_GET['action']) && $_GET['action'] === 'vlt_download_logs'
            && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'vlt_download_logs')) {
            $this->downloadLogsZip();
            return;
        }

        $logging   = get_option('vlt_cm_logging', true);
        // Auto-detect Cloudflare: default off if domain not behind CF
        $cf_default = self::isDomainBehindCloudflare(parse_url(home_url(), PHP_URL_HOST) ?? '');
        $cf_track  = get_option('vlt_cm_cf_tracking', $cf_default);
        $log_days  = (int) get_option('vlt_cm_log_days', 30);
        $debug_on  = isset($_COOKIE['vlt_debug_cache']);
        $dropin_ok = Plugin::instance()->dropin()->isOurs();

        $redis_socket = get_option('vlt_redis_socket', '');
        $redis_host   = get_option('vlt_redis_host', '');
        $redis_port   = (int) get_option('vlt_redis_port', 0);
        $ls_purge     = get_option('vlt_litespeed_purge', false);
        $img_enabled  = get_option('vlt_img_optm_enabled', false);
        $img_webp     = get_option('vlt_img_optm_serve_webp', true);
        $img_quality  = (int) get_option('vlt_img_optm_quality', 82);

        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');

        echo '<div class="wrap"><h1>Podėlio Valdymas — Nustatymai</h1>';
        echo '<form method="post"><table class="form-table">';
        wp_nonce_field('vlt_cm_settings');

        echo '<tr><th>Debug režimas</th><td>';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_toggle_debug'), 'vlt_toggle_debug')) . '" class="button">' . ($debug_on ? 'Išjungti debug' : 'Įjungti debug') . '</a>';
        echo '<p class="description">Nustato slapuką vlt_debug_cache šiai sesijai.</p></td></tr>';

        echo '<tr><th>Užklausų registravimas</th><td><label><input type="checkbox" name="vlt_cm_logging" value="1"' . checked($logging, true, false) . '> Įjungtas</label></td></tr>';
        echo '<tr><th>Cloudflare stebėjimas</th><td>';
        $cf_behind = self::isDomainBehindCloudflare(parse_url(home_url(), PHP_URL_HOST) ?? '');
        echo '<label><input type="checkbox" name="vlt_cm_cf_tracking" id="vlt_cm_cf_tracking" value="1"' . checked($cf_track, true, false) . '> Įjungtas</label>';
        if (!$cf_behind) {
            echo '<p class="description" style="color:#d63638" id="vlt_cf_warning">⚠ Jūsų domenas nenaudoja Cloudflare DNS / proxy — Cloudflare užklausų nebus, todėl šis stebėjimas neturi prasmės.</p>';
        }
        echo '</td></tr>';
        echo '<script>
        document.getElementById("vlt_cm_cf_tracking")?.addEventListener("change", function() {
            var warn = document.getElementById("vlt_cf_warning");
            if (this.checked && warn) {
                if (!confirm("J\u016bs\u0173 domenas nenaudoja Cloudflare DNS arba proxy. Cloudflare u\u017eklaus\u0173 nebus \u2014 ar tikrai norite \u012fjungti \u0161\u012f steb\u0117jim\u0105?")) {
                    this.checked = false;
                }
            }
        });
        </script>';
        echo '<tr><th>Žurnalų saugojimas (dienų)</th><td><input type="number" name="vlt_cm_log_days" value="' . $log_days . '" min="1" max="365" style="width:80px"></td></tr>';

        $logPath   = get_option('vlt_cm_log_path', WP_CONTENT_DIR . '/uploads/vlt-cache-logs');
        $tracePath = get_option('vlt_cm_trace_path', WP_CONTENT_DIR . '/uploads/vlt-traces');
        echo '<tr><th>Žurnalų kelias</th><td><input type="text" name="vlt_cm_log_path" value="' . esc_attr($logPath) . '" class="regular-text"><p class="description">Talpyklos žurnalų saugojimo vieta</p></td></tr>';
        echo '<tr><th>Pėdsakų kelias</th><td><input type="text" name="vlt_cm_trace_path" value="' . esc_attr($tracePath) . '" class="regular-text"><p class="description">Tracer pėdsakų saugojimo vieta</p></td></tr>';

        $logMaxMb   = (int) get_option('vlt_cm_log_max_mb', 500);
        $traceMaxMb = (int) get_option('vlt_cm_trace_max_mb', 200);
        echo '<tr><th>Max žurnalų dydis (MB)</th><td><input type="number" name="vlt_cm_log_max_mb" value="' . $logMaxMb . '" min="0" max="10000" style="width:80px"><p class="description">0 = neribota. Seniausi failai trinami viršijus limitą.</p></td></tr>';
        echo '<tr><th>Max pėdsakų dydis (MB)</th><td><input type="number" name="vlt_cm_trace_max_mb" value="' . $traceMaxMb . '" min="0" max="10000" style="width:80px"><p class="description">0 = neribota. Seniausi failai trinami viršijus limitą.</p></td></tr>';

        $traceHooks = get_option('vlt_trace_hooks', false);
        $traceHookMs = (float) get_option('vlt_trace_hook_threshold_ms', 1.0);
        echo '<tr><th>Hook argumentų sekimas</th><td>';
        echo '<label><input type="checkbox" name="vlt_trace_hooks" value="1"' . checked($traceHooks, true, false) . '> Įjungti</label>';
        echo '<p class="description">Fiksuoja kiekvieno WP hook iškvietimą su argumentais, laiku ir iškvietimo vieta. ⚠ Padidina apkrovą — naudokite tik derinimui.</p>';
        echo '</td></tr>';
        echo '<tr><th>Hook sekimo slenkstis (ms)</th><td>';
        echo '<input type="number" name="vlt_trace_hook_threshold_ms" value="' . esc_attr($traceHookMs) . '" min="0.1" max="1000" step="0.1" style="width:80px"> ms';
        echo '<p class="description">Fiksuojami tik lėtesni nei nurodytas laikas hook\'ai. Rekomenduojama: 1ms.</p>';
        echo '</td></tr>';

        echo '</table>';

        // ── Redis connection section ──────────────────────────────────────────
        ?>
        <h2>Redis ryšys</h2>
        <div style="margin-bottom:12px">
            <button type="button" id="vlt-redis-detect-btn" class="button button-primary">🔍 Aptikti Redis automatiškai</button>
            <span id="vlt-redis-detect-status" style="margin-left:10px;color:#666"></span>
            <div id="vlt-redis-detect-result" style="margin-top:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;display:none;white-space:pre-wrap;font-family:monospace;font-size:12px"></div>
        </div>

        <?php
        $mode = $redis_socket ? 'socket' : 'tcp';
        ?>
        <div style="margin-bottom:8px">
            <label style="margin-right:16px">
                <input type="radio" name="vlt_redis_mode" value="socket" <?php checked($mode, 'socket'); ?> onchange="vltRedisMode(this.value)">
                Unix Socket <small style="color:#666">(greičiau, rekomenduojama)</small>
            </label>
            <label>
                <input type="radio" name="vlt_redis_mode" value="tcp" <?php checked($mode, 'tcp'); ?> onchange="vltRedisMode(this.value)">
                TCP Host:Port
            </label>
        </div>

        <table class="form-table">
        <tbody id="vlt-redis-socket-row" <?php echo $mode === 'tcp' ? 'style="display:none"' : ''; ?>>
        <tr><th>Unix socket kelias</th><td>
            <input type="text" name="vlt_redis_socket" id="vlt_redis_socket" value="<?php echo esc_attr($redis_socket); ?>" class="regular-text" placeholder="/home/user/.redis/redis.sock">
            <p class="description">Jei nurodyta — naudojamas socket (greičiau). Palikite tuščią naudoti TCP.</p>
        </td></tr>
        </tbody>
        <tbody id="vlt-redis-tcp-row" <?php echo $mode === 'socket' ? 'style="display:none"' : ''; ?>>
        <tr><th>Host</th><td>
            <input type="text" name="vlt_redis_host" id="vlt_redis_host" value="<?php echo esc_attr($redis_host); ?>" class="regular-text" placeholder="127.0.0.1">
        </td></tr>
        <tr><th>Port</th><td>
            <input type="number" name="vlt_redis_port" id="vlt_redis_port" value="<?php echo esc_attr($redis_port ?: ''); ?>" style="width:100px" placeholder="6379">
            <p class="description">Palikite tuščią — bus naudojamas automatinis aptikimas.</p>
        </td></tr>
        </tbody>
        </table>

        <script>
        function vltRedisMode(mode) {
            document.getElementById('vlt-redis-socket-row').style.display = mode === 'socket' ? '' : 'none';
            document.getElementById('vlt-redis-tcp-row').style.display   = mode === 'tcp'    ? '' : 'none';
            if (mode === 'socket') {
                document.getElementById('vlt_redis_host').value = '';
                document.getElementById('vlt_redis_port').value = '';
            } else {
                document.getElementById('vlt_redis_socket').value = '';
            }
        }
        document.getElementById('vlt-redis-detect-btn').addEventListener('click', function() {
            const status = document.getElementById('vlt-redis-detect-status');
            const result = document.getElementById('vlt-redis-detect-result');
            status.textContent = 'Aptinkama...';
            result.style.display = 'none';
            fetch('<?php echo $rest_url; ?>/redis/detect', {headers: {'X-WP-Nonce': '<?php echo $nonce; ?>'}})
                .then(r => r.json())
                .then(d => {
                    status.textContent = d.connected ? '✅ Redis rastas!' : '❌ Redis nerastas';
                    if (d.connected) {
                        if (d.method === 'socket') {
                            document.querySelector('[name=vlt_redis_mode][value=socket]').checked = true;
                            vltRedisMode('socket');
                            document.getElementById('vlt_redis_socket').value = d.socket;
                        } else {
                            document.querySelector('[name=vlt_redis_mode][value=tcp]').checked = true;
                            vltRedisMode('tcp');
                            document.getElementById('vlt_redis_host').value = d.host;
                            document.getElementById('vlt_redis_port').value = d.port;
                        }
                        result.style.display = 'block';
                        result.style.borderColor = '#46b450';
                        result.textContent = 'Metodas: ' + d.method
                            + (d.socket ? '\nSocket: ' + d.socket : '\nHost: ' + d.host + ':' + d.port)
                            + (d.version ? '\nVersija: ' + d.version : '')
                            + '\nServeris: ' + d.panel;
                    } else {
                        result.style.display = 'block';
                        result.style.borderColor = '#dc3232';
                        result.textContent = d.instructions || 'Redis nerastas.';
                    }
                })
                .catch(() => { status.textContent = '❌ Klaida'; });
        });
        </script>

        <?php
        // ── LiteSpeed section ─────────────────────────────────────────────────
        $ls_detected = \VLT\CacheManager\Redis\RedisDetector::detectLiteSpeed();
        echo '<h2>LiteSpeed / OpenLiteSpeed talpykla</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>LiteSpeed aptiktas</th><td>' . ($ls_detected ? '<span style="color:#46b450">✅ Taip</span>' : '<span style="color:#999">Ne</span>') . '</td></tr>';
        echo '<tr><th>Valyti LiteSpeed talpyklą</th><td><label><input type="checkbox" name="vlt_litespeed_purge" value="1"' . checked($ls_purge, true, false) . '> Įjungti LiteSpeed talpyklos valymą po publikavimo</label>';
        echo '<p class="description">Naudoja <code>litespeed_purge_all()</code> arba <code>do_action("litespeed_purge_all")</code>.</p></td></tr>';
        echo '</table>';

        echo '<p class="submit"><button class="button button-primary" type="submit">Išsaugoti nustatymus</button></p>';
        echo '</form>';

        // ── Image optimization status + bulk run ──────────────────────────────
        $imgStatus = \VLT\CacheManager\Image\ImageOptimizer::status();
        echo '<h2>Paveikslėlių optimizavimas</h2>';
        if ($imgStatus['lscwp']) {
            echo '<p>✅ LiteSpeed Cache įskiepis aktyvus — naudojamas QUIC.cloud paveikslėlių optimizavimas.</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=litespeed-img_optm')) . '" class="button">Atidaryti LSCWP paveikslėlių optimizavimą →</a></p>';
        } else {
            $gd      = $imgStatus['gd'] ? '✅ GD (WebP)' : '❌ GD WebP nepasiekiamas';
            $imagick = $imgStatus['imagick'] ? '✅ Imagick (WebP)' : '❌ Imagick WebP nepasiekiamas';
            $avif    = ($imgStatus['avif'] ?? false) ? '✅ Imagick (AVIF)' : '❌ AVIF nepasiekiamas';
            echo '<p>' . $gd . ' &nbsp; ' . $imagick . ' &nbsp; ' . $avif . '</p>';
            echo '<p><small>Formatas: AVIF (jei palaikomas) → WebP → originalas (kaip atsarginis)</small></p>';
            echo '<p>Iš viso paveikslėlių: <strong>' . $imgStatus['total'] . '</strong> &nbsp; '
                . 'Optimizuota: <strong>' . $imgStatus['optimized'] . '</strong> &nbsp; '
                . 'Laukia: <strong>' . $imgStatus['pending'] . '</strong></p>';
            if (!$imgStatus['gd'] && !$imgStatus['imagick']) {
                echo '<div class="notice notice-warning inline"><p>WebP konvertavimui reikalingas GD su WebP palaikymu arba Imagick. '
                    . 'Įdiekite <code>php-gd</code> arba <code>php-imagick</code>.</p></div>';
            }
            echo '<button type="button" id="vlt-img-optm-run" class="button button-primary" '
                . ($imgStatus['pending'] === 0 ? 'disabled' : '') . '>'
                . 'Optimizuoti laukiančius (' . $imgStatus['pending'] . ')</button> '
                . '<span id="vlt-img-optm-status" style="margin-left:10px;color:#666"></span>';
            ?>
            <script>
            document.getElementById('vlt-img-optm-run')?.addEventListener('click', function() {
                const btn = this;
                const status = document.getElementById('vlt-img-optm-status');
                btn.disabled = true;
                status.textContent = 'Vykdoma...';
                fetch('<?php echo esc_js(rest_url('vlt-cache/v1/img-optm/run')); ?>', {
                    method: 'POST',
                    headers: {'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>', 'Content-Type': 'application/json'},
                    body: JSON.stringify({limit: 100})
                }).then(r => r.json()).then(d => {
                    if (d.delegated) {
                        status.textContent = '✅ Perduota LSCWP';
                    } else {
                        status.textContent = '✅ Apdorota: ' + d.processed + ', praleista: ' + d.skipped + ', klaidos: ' + d.errors;
                    }
                    setTimeout(() => location.reload(), 2000);
                }).catch(() => { status.textContent = '❌ Klaida'; btn.disabled = false; });
            });
            </script>
            <?php
        }

        echo '<h3>Nustatymai</h3>';
        echo '<table class="form-table">';
        echo '<form method="post">';
        wp_nonce_field('vlt_cm_settings');
        echo '<tr><th>Įjungti WebP konvertavimą</th><td><label><input type="checkbox" name="vlt_img_optm_enabled" value="1"' . checked($img_enabled, true, false) . '> Konvertuoti JPEG/PNG į WebP įkeliant</label></td></tr>';
        echo '<tr><th>Pateikti WebP naršyklėms</th><td><label><input type="checkbox" name="vlt_img_optm_serve_webp" value="1"' . checked($img_webp, true, false) . '> Pakeisti paveikslėlių URL į .webp (jei failas egzistuoja)</label></td></tr>';
        echo '<tr><th>WebP kokybė</th><td><input type="number" name="vlt_img_optm_quality" value="' . esc_attr($img_quality) . '" min="1" max="100" style="width:70px"> <span class="description">1–100, rekomenduojama 80–85</span></td></tr>';
        echo '</table>';
        echo '<p class="submit"><button class="button button-primary" type="submit">Išsaugoti nustatymus</button></p>';
        echo '</form>';

        echo '<h2>Veiksmai</h2><p>';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=vlt-cache-settings&action=vlt_download_logs'), 'vlt_download_logs')) . '" class="button">Atsisiųsti žurnalus (ZIP)</a> ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_install_dropin'), 'vlt_install_dropin')) . '" class="button">' . ($dropin_ok ? 'Perdiegti object-cache.php' : 'Įdiegti object-cache.php') . '</a> ';
        $srv = \VLT\CacheManager\ServerDetector::detect();
        echo '<button type="button" id="vlt-server-redetect" class="button">🔍 Iš naujo aptikti serverį (' . esc_html($srv['server']) . ')</button>';
        echo '<span id="vlt-server-redetect-status" style="margin-left:8px;color:#666"></span>';
        echo '</p>';
        echo '<script>
        document.getElementById("vlt-server-redetect").addEventListener("click", function() {
            const s = document.getElementById("vlt-server-redetect-status");
            this.disabled = true; s.textContent = "Aptinkama...";
            fetch("' . esc_js(rest_url('vlt-cache/v1/server-detect')) . '", {
                method: "POST",
                headers: {"X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"}
            }).then(r => r.json()).then(d => {
                s.textContent = "✅ Aptikta: " + d.server + (d.version ? " v" + d.version : "");
                setTimeout(() => location.reload(), 1500);
            }).catch(() => { s.textContent = "❌ Klaida"; this.disabled = false; });
        });
        </script>';

        // Log files section
        $this->renderLogFiles();

        echo '</div>';
    }

    private static function isDomainBehindCloudflare(string $host): bool
    {
        if (!$host) {
            return false;
        }
        // Cloudflare IPv4 ranges (from https://www.cloudflare.com/ips-v4)
        $cfRanges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        ];

        $ips = @dns_get_record($host, DNS_A);
        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $record) {
            $ip = $record['ip'] ?? '';
            if (!$ip) {
                continue;
            }
            $ipLong = ip2long($ip);
            foreach ($cfRanges as $range) {
                [$subnet, $bits] = explode('/', $range);
                $mask = ~((1 << (32 - (int) $bits)) - 1);
                if (($ipLong & $mask) === (ip2long($subnet) & $mask)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function renderLogFiles(): void
    {
        $logDir   = WP_CONTENT_DIR . '/uploads/vlt-cache-logs';
        $traceDir = WP_CONTENT_DIR . '/uploads/vlt-traces';
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');

        $logFiles   = glob($logDir . '/cache-log-*.json') ?: [];
        $traceFiles = glob($traceDir . '/trace-*.json') ?: [];
        rsort($logFiles);
        rsort($traceFiles);
        ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.14.9/cdn.min.js" defer></script>

        <h2 class="mt-6 mb-2 text-lg font-semibold">Žurnalų failai</h2>
        <div x-data="vltLogs()" class="text-xs">
            <div class="grid grid-cols-2 gap-4">
                <!-- Cache logs -->
                <div>
                    <h3 class="font-semibold mb-2">Talpyklos žurnalai</h3>
                    <div class="bg-white border border-gray-200 rounded max-h-48 overflow-auto">
                        <?php foreach ($logFiles as $f): ?>
                        <div class="flex justify-between items-center px-3 py-1.5 border-b border-gray-50 hover:bg-gray-50 cursor-pointer" @click="loadFile('<?php echo esc_js(basename($f)); ?>', 'log')">
                            <span><?php echo esc_html(basename($f)); ?></span>
                            <span class="text-gray-400"><?php echo esc_html(size_format(filesize($f))); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$logFiles): ?><p class="p-3 text-gray-400">Nėra failų</p><?php endif; ?>
                    </div>
                </div>
                <!-- Trace logs -->
                <div>
                    <h3 class="font-semibold mb-2">Pėdsakų žurnalai</h3>
                    <div class="bg-white border border-gray-200 rounded max-h-48 overflow-auto">
                        <?php foreach ($traceFiles as $f): ?>
                        <div class="flex justify-between items-center px-3 py-1.5 border-b border-gray-50 hover:bg-gray-50 cursor-pointer" @click="loadFile('<?php echo esc_js(basename($f)); ?>', 'trace')">
                            <span><?php echo esc_html(basename($f)); ?></span>
                            <span class="text-gray-400"><?php echo esc_html(size_format(filesize($f))); ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$traceFiles): ?><p class="p-3 text-gray-400">Nėra failų</p><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- JSON Viewer -->
            <div x-show="viewing" class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold" x-text="'📄 '+currentFile"></h3>
                    <div class="flex gap-2">
                        <span class="text-gray-500" x-text="entries.length+' įrašų'"></span>
                        <input type="text" class="border border-gray-300 rounded px-2 py-0.5 w-48" x-model="search" placeholder="Filtruoti...">
                        <button class="px-2 py-0.5 bg-gray-200 rounded hover:bg-gray-300" @click="viewing=false">✕ Uždaryti</button>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded overflow-auto" style="max-height:500px">
                    <template x-for="(e,i) in filteredEntries" :key="i">
                        <div class="border-b border-gray-100">
                            <div class="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-gray-50" @click="e._open=!e._open">
                                <span class="text-gray-400 w-4" x-text="e._open?'▾':'▸'"></span>
                                <span class="text-gray-500 w-40 shrink-0" x-text="e.timestamp||e.ts||''"></span>
                                <span class="px-1.5 py-0.5 rounded text-[10px]" :class="{'bg-yellow-50':e.type==='purge','bg-green-50':e.type==='stats','bg-blue-50':e.type==='cloudflare','bg-red-50':e.type==='error','bg-purple-50':e.method}" x-text="e.type||e.method||'—'"></span>
                                <span class="truncate text-gray-600" x-text="e.uri||e.details_str||JSON.stringify(e.details||'').substring(0,80)"></span>
                            </div>
                            <div x-show="e._open" class="px-3 pb-2">
                                <pre class="bg-gray-900 text-green-300 p-3 rounded text-[10px] overflow-auto max-h-64 whitespace-pre-wrap" x-text="JSON.stringify(e,null,2)"></pre>
                            </div>
                        </div>
                    </template>
                    <p x-show="filteredEntries.length===0" class="p-3 text-gray-400">Nėra įrašų</p>
                </div>
                <div class="mt-2 flex gap-2" x-show="entries.length>pageSize">
                    <button class="px-2 py-0.5 bg-gray-200 rounded" :disabled="page<=1" @click="page--">←</button>
                    <span class="text-gray-500 py-0.5" x-text="page+'/'+Math.ceil(entries.length/pageSize)"></span>
                    <button class="px-2 py-0.5 bg-gray-200 rounded" :disabled="page>=Math.ceil(entries.length/pageSize)" @click="page++">→</button>
                </div>
            </div>
        </div>

        <script>
        function vltLogs(){return{
            viewing:false,currentFile:'',entries:[],search:'',page:1,pageSize:100,
            async loadFile(name,type){
                this.currentFile=name;this.page=1;this.search='';this.entries=[];this.viewing=true;
                const endpoint=type==='trace'?'tracer/history':'logs';
                const date=name.match(/(\d{4}-\d{2}-\d{2})/)?.[1]||'';
                try{
                    const r=await fetch('<?php echo $rest_url; ?>/'+endpoint+'?date='+date,{headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});
                    const d=await r.json();
                    this.entries=(Array.isArray(d)?d:(d.rows||[])).map(e=>({...e,_open:false}));
                }catch(e){}
            },
            get filteredEntries(){
                let e=this.entries;
                if(this.search){const s=this.search.toLowerCase();e=e.filter(x=>JSON.stringify(x).toLowerCase().includes(s))}
                return e.slice((this.page-1)*this.pageSize,this.page*this.pageSize);
            }
        }}
        </script>
        <?php
    }

    private function downloadLogsZip(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Neautorizuota');
        }
        $logDir = WP_CONTENT_DIR . '/uploads/vlt-cache-logs';
        $files  = glob($logDir . '/cache-log-*.json');
        if (!$files) {
            wp_die('Žurnalų failų nerasta.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'vlt_logs_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $f) {
            $zip->addFile($f, basename($f));
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="vlt-cache-logs-' . gmdate('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
