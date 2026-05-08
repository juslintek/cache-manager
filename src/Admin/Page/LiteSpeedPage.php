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
        echo '<div>'; // explicit block wrapper to prevent form/table nesting issues

        // ── Status ────────────────────────────────────────────────────────────
        echo '<table class="widefat fixed striped tw-max-w-4xl tw-my-5"><thead><tr><th style="width:200px">Parametras</th><th style="width:280px">Būsena</th><th>Kaip įjungti / taisyti</th></tr></thead><tbody>';
        echo '<tr><td><strong>Serveris</strong></td><td>' . esc_html($label) . ($info['version'] ? ' v' . esc_html($info['version']) : '') . '</td><td>—</td></tr>';

        // Config files — owned by lsadm:apache, PHP process (user) can't read them
        $configFile    = $info['config']['config_file'] ?? '';
        $knownConfigs  = ['/etc/openlitespeed/httpd_config.conf', '/etc/openlitespeed/httpd-lscache.conf'];
        echo '<tr><td><strong>Konfigūracijos failai</strong></td><td>';
        if ($configFile) {
            echo '<code class="tw-text-xs">' . esc_html($configFile) . '</code>';
        } else {
            echo '<span class="tw-text-yellow-600">⚠ Nepasiekiami PHP procesui</span><br>';
            foreach ($knownConfigs as $cf) {
                echo '<code class="tw-text-[10px] tw-text-gray-400">' . esc_html($cf) . '</code><br>';
            }
        }
        echo '</td><td>';
        if (!$configFile) {
            echo '<small>Failai priklauso <code>lsadm:apache</code> grupei. Norėdami leisti PHP skaityti:<br>';
            echo '<code>sudo usermod -aG apache ' . esc_html(get_current_user()) . ' && sudo systemctl reload lsws</code></small>';
        } else { echo '—'; }
        echo '</td></tr>';

        // LSCache module
        $lscacheAny = $info['config']['lscache'] ?? false;
        echo '<tr><td><strong>LSCache modulis</strong></td><td>';
        if ($lscacheAny) {
            echo '<span class="tw-text-green-600">✅ Rastas</span>';
            $signals = array_filter([
                ($info['config']['lscache_php_api'] ?? false) ? 'PHP API' : '',
                ($info['config']['lscache_module'] ?? false)  ? 'ls_enabled=1' : '',
                ($info['config']['lscache_conf'] ?? false)    ? 'konfigūracija' : '',
                ($info['config']['lscache_headers'] ?? false) ? 'HTTP antraštė' : '',
            ]);
            if ($signals) echo ' <small class="tw-text-gray-400">(' . implode(', ', $signals) . ')</small>';
        } else {
            echo '<span class="tw-text-red-600">❌ Nerastas</span>';
        }
        echo '</td><td>';
        if (!$lscacheAny) {
            echo '<a href="https://docs.litespeedtech.com/lscache/lscwp/installation/" target="_blank" class="button button-small">📖 Įjungimo instrukcija →</a>';
        } else { echo '—'; }
        echo '</td></tr>';

        // enableCache
        $cacheActive = $info['config']['lscache_active'] ?? false;
        echo '<tr><td><strong>Talpykla įjungta</strong></td><td>';
        echo $cacheActive
            ? '<span class="tw-text-green-600">✅ enableCache = 1</span>'
            : '<span class="tw-text-red-600">❌ enableCache = 0</span> — talpykla išjungta';
        echo '</td><td>';
        if (!$cacheActive) {
            echo '<a href="https://docs.litespeedtech.com/lscache/lscwp/installation/#directadmin" target="_blank" class="button button-small">📖 DirectAdmin instrukcija →</a>';
        } else { echo '—'; }
        echo '</td></tr>';

        // LSCWP
        $lscwpActive = defined('LSCWP_V') || class_exists('LiteSpeed\Core');
        echo '<tr><td><strong>LSCWP įskiepis</strong></td><td>';
        echo $lscwpActive
            ? '<span class="tw-text-yellow-600">⚠ Aktyvus — gali konfliktuoti</span>'
            : '<span class="tw-text-green-600">✅ Neaktyvus — šis įskiepis valdo talpyklą</span>';
        echo '</td><td>—</td></tr>';

        if (!empty($info['config']['lscache_storage_path'])) {
            echo '<tr><td><strong>Talpyklos katalogas</strong></td><td><code class="tw-text-xs">' . esc_html($info['config']['lscache_storage_path']) . '</code></td><td>—</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // end status block

        // ── Cache settings ────────────────────────────────────────────────────
        echo '<h2>Talpyklos nustatymai</h2>';
        echo '<p class="tw-text-gray-500">Šis įskiepis siunčia <code>X-LiteSpeed-Cache-Control</code> antraštes — tą patį mechanizmą naudoja LSCWP. Nereikia jokio papildomo įskiepio.</p>';

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
            '<input type="number" name="vlt_ls_cache_ttl" value="' . esc_attr($ttl) . '" min="60" class="tw-w-24"> sekundžių',
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


        // ── Performance optimizations ─────────────────────────────────────────
        echo '<h2>Papildomos optimizacijos</h2>';
        echo '<table class="widefat fixed striped tw-max-w-4xl tw-mb-5"><thead><tr><th style="width:220px">Optimizacija</th><th style="width:100px">Būsena</th><th>Aprašymas</th></tr></thead><tbody>';
        $htaccess = @file_get_contents(ABSPATH . '.htaccess') ?: '';
        $opts = [
            ['name'=>'Native Lazy Loading','enabled'=>(int)get_option('wp_lazy_loading_enabled',1)>0,'desc'=>'WordPress 5.5+ automatiškai prideda loading="lazy" paveikslėliams.','link'=>'https://make.wordpress.org/core/2020/07/14/lazy-loading-images-in-5-5/'],
            ['name'=>'HTTP/3 (QUIC)','enabled'=>str_contains($htaccess,'QUIC')||str_contains($htaccess,'quic'),'desc'=>'LiteSpeed palaiko HTTP/3 — iki 3× greitesnis nei HTTP/2 mobiliuose tinkluose.','link'=>'https://docs.litespeedtech.com/lsws/http3/'],
            ['name'=>'Browser Cache (Expires)','enabled'=>str_contains($htaccess,'ExpiresByType')||str_contains($htaccess,'Cache-Control'),'desc'=>'Naršyklės talpykla statiniams failams. Sumažina pakartotinių apsilankymų laiką.','link'=>'https://docs.litespeedtech.com/lscache/lscwp/cache/#browser-cache'],
            ['name'=>'Gzip / Brotli','enabled'=>str_contains($htaccess,'mod_deflate')||str_contains($htaccess,'compress'),'desc'=>'LiteSpeed automatiškai suspaudžia atsakymus. Patikrinkite ar įjungta serverio lygiu.','link'=>'https://docs.litespeedtech.com/lsws/config/compression/'],
            ['name'=>'Critical CSS','enabled'=>defined('LSCWP_V')||class_exists('LiteSpeed\\Core'),'desc'=>'Kritinis CSS įkelia puslapį be blokuojančių stilių.','link'=>'https://docs.litespeedtech.com/lscache/lscwp/pageopt/#css-settings'],
            ['name'=>'DNS Prefetch','enabled'=>has_action('wp_head','wp_resource_hints'),'desc'=>'WordPress automatiškai prideda DNS prefetch antraštes.','link'=>'https://developer.wordpress.org/reference/functions/wp_resource_hints/'],
        ];
        foreach ($opts as $o) {
            $c=$o['enabled']?'text-green-600':'text-yellow-600';
            echo '<tr><td><strong>'.esc_html($o['name']).'</strong></td>';
            echo '<td class="'.esc_attr($c).'">'.(($o['enabled'])?'✅ Aktyvus':'⚠ Patikrinti').'</td>';
            echo '<td class="text-xs text-gray-600">'.esc_html($o['desc']).($o['link']?' <a href="'.esc_url($o['link']).'" target="_blank" class="text-blue-600">Dokumentacija →</a>':''). '</td></tr>';
        }
        echo '</tbody></table>';

        // ── Cache explorer ────────────────────────────────────────────────────
        $cacheDir = $info['cacheDir'] ?? '/usr/local/lsws/cachedata';
        if ($cacheDir && @is_dir($cacheDir)) {
            echo '<h2>Talpyklos naršyklė</h2>';
            $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS));
            $files = 0;
            $size  = 0;
            foreach ($it as $f) {
                if ($f->isFile() && !str_starts_with($f->getFilename(), '.')) {
                    $files++;
                    $size += $f->getSize();
                }
            }
            echo '<p>Failų: <strong>' . number_format($files) . '</strong> &nbsp; Dydis: <strong>' . Plugin::formatSize($size) . '</strong> &nbsp; Katalogas: <code>' . esc_html($cacheDir) . '</code></p>';
        }
        echo Plugin::purgeButton('litespeed', 'Valyti LiteSpeed talpyklą');
        echo Plugin::purgeButton('all', 'Valyti viską');
        echo '</p>';

        $hierarchy = $info['config']['config_hierarchy'] ?? [];
        if ($hierarchy) {
            echo '<h2>Konfigūracijos failai</h2>';
            $nonce = wp_create_nonce('wp_rest');
            $restUrl = esc_js(rest_url('vlt-cache/v1'));
            foreach ($hierarchy as $entry) {
                $exists   = $entry['exists'] ?? false;
                $readable = $entry['readable'] ?? false;
                $writable = $entry['writable'] ?? false;
                $path     = $entry['path'];
                $safeId   = 'cfg-' . md5($path);

                echo '<div class="tw-mb-4 tw-border tw-border-gray-200 tw-rounded tw-overflow-hidden">';

                // Header bar
                $roleColors = [
                    'main'            => '#2271b1',
                    'lscache'         => '#00a32a',
                    'vhosts'          => '#6b3fa0',
                    'vhosts-da'       => '#6b3fa0',
                    'template-master' => '#b26200',
                    'template-custom' => '#c9356e',
                ];
                $color = $roleColors[$entry['role']] ?? '#666';
                echo '<div style="background:' . $color . ';color:#fff;padding:8px 12px;display:flex;justify-content:space-between;align-items:center">';
                echo '<div><strong>' . esc_html($entry['label']) . '</strong> <code class="tw-bg-black/20 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px]">' . esc_html($path) . '</code></div>';
                echo '<div class="tw-flex tw-gap-1.5 tw-items-center">';
                if (!$exists) {
                    echo '<span style="background:rgba(0,0,0,.3);padding:2px 8px;border-radius:3px;font-size:11px">Neegzistuoja</span>';
                } else {
                    echo '<span style="background:rgba(0,0,0,.2);padding:2px 8px;border-radius:3px;font-size:11px">' . ($writable ? '✏ Redaguojamas' : '👁 Tik skaitymas') . '</span>';
                    echo '<button type="button" onclick="vltToggleConfig(\'' . esc_js($safeId) . '\')" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:3px 10px;border-radius:3px;cursor:pointer;font-size:12px">Rodyti ▾</button>';
                }
                if ($entry['role'] === 'template-custom' && !$exists) {
                    echo '<button type="button" onclick="vltCreateCustom(\'' . esc_js($path) . '\',\'' . esc_js($safeId) . '\')" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:3px 10px;border-radius:3px;cursor:pointer;font-size:12px">Sukurti</button>';
                }
                echo '</div></div>';

                // Note
                echo '<div class="tw-px-3 tw-py-1.5 tw-bg-gray-50 tw-text-xs tw-text-gray-500 tw-border-b tw-border-gray-100">' . esc_html($entry['note']) . '</div>';

                // Content panel (hidden by default)
                if ($exists && $readable) {
                    $content = esc_attr(@file_get_contents($path) ?: '');
                    echo '<div id="' . esc_attr($safeId) . '" class="tw-hidden">';
                    if ($writable) {
                        echo '<textarea id="' . esc_attr($safeId) . '-ta" style="width:100%;height:400px;font-family:monospace;font-size:12px;border:none;padding:12px;background:#1e1e1e;color:#d4d4d4;box-sizing:border-box" spellcheck="false">' . esc_textarea(@file_get_contents($path) ?: '') . '</textarea>';
                        echo '<div style="padding:8px 12px;background:#f0f0f0;display:flex;gap:8px;align-items:center">';
                        echo '<button type="button" onclick="vltSaveConfig(\'' . esc_js($path) . '\',\'' . esc_js($safeId) . '\')" class="button button-primary">Išsaugoti</button>';
                        echo '<span id="' . esc_attr($safeId) . '-status" style="color:#666;font-size:12px"></span>';
                        echo '<span style="color:#d63638;font-size:11px">⚠ Klaidos šiame faile gali sustabdyti serverį. Prieš keičiant padarykite atsarginę kopiją.</span>';
                        echo '</div>';
                    } else {
                        echo '<pre style="margin:0;padding:12px;background:#1e1e1e;color:#d4d4d4;font-size:12px;overflow:auto;max-height:400px">' . esc_html(@file_get_contents($path) ?: '') . '</pre>';
                    }
                    echo '</div>';
                }

                echo '</div>';
            }

            echo '<script>
            function vltToggleConfig(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = el.style.display === "none" ? "block" : "none";
            }
            function vltSaveConfig(path, id) {
                var ta = document.getElementById(id + "-ta");
                var status = document.getElementById(id + "-status");
                if (!ta) return;
                status.textContent = "Saugoma...";
                fetch("' . $restUrl . '/config-save", {
                    method: "POST",
                    headers: {"X-WP-Nonce": "' . $nonce . '", "Content-Type": "application/json"},
                    body: JSON.stringify({path: path, content: ta.value})
                }).then(r => r.json()).then(d => {
                    status.textContent = d.ok ? "✅ Išsaugota" : "❌ " + (d.error || "Klaida");
                    status.style.color = d.ok ? "#00a32a" : "#d63638";
                }).catch(() => { status.textContent = "❌ Klaida"; status.style.color = "#d63638"; });
            }
            function vltCreateCustom(path, id) {
                var masterPath = path.replace("/custom/", "/").replace("custom/", "");
                fetch("' . $restUrl . '/config-save", {
                    method: "POST",
                    headers: {"X-WP-Nonce": "' . $nonce . '", "Content-Type": "application/json"},
                    body: JSON.stringify({path: path, content: "# Custom OLS vhost template override\n# Pakeitimai čia perrašo: " + masterPath + "\n"})
                }).then(r => r.json()).then(d => {
                    if (d.ok) location.reload();
                });
            }
            </script>';
        }

        echo '</div>';
    }

    private static function row(string $label, string $input, string $desc): void
    {
        echo '<tr><th class="tw-w-64">' . esc_html($label) . '</th><td>';
        echo '<label>' . $input . '</label>';
        echo '<p class="description">' . $desc . '</p>';
        echo '</td></tr>';
    }
}
