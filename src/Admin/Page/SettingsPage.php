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
            update_option('vlt_cm_logging', !empty($_POST['vlt_cm_logging']));
            update_option('vlt_cm_cf_tracking', !empty($_POST['vlt_cm_cf_tracking']));
            update_option('vlt_cm_log_days', max(1, (int) ($_POST['vlt_cm_log_days'] ?? 30)));
            if (!empty($_POST['vlt_cm_log_path'])) {
                update_option('vlt_cm_log_path', sanitize_text_field($_POST['vlt_cm_log_path']));
            }
            if (!empty($_POST['vlt_cm_trace_path'])) {
                update_option('vlt_cm_trace_path', sanitize_text_field($_POST['vlt_cm_trace_path']));
            }
            update_option('vlt_cm_log_max_mb', max(0, (int) ($_POST['vlt_cm_log_max_mb'] ?? 500)));
            update_option('vlt_cm_trace_max_mb', max(0, (int) ($_POST['vlt_cm_trace_max_mb'] ?? 200)));
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
        $cf_track  = get_option('vlt_cm_cf_tracking', true);
        $log_days  = (int) get_option('vlt_cm_log_days', 30);
        $debug_on  = isset($_COOKIE['vlt_debug_cache']);
        $dropin_ok = Plugin::instance()->dropin()->isOurs();

        echo '<div class="wrap"><h1>Podėlio Valdymas — Nustatymai</h1>';
        echo '<form method="post"><table class="form-table">';
        wp_nonce_field('vlt_cm_settings');

        echo '<tr><th>Debug režimas</th><td>';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_toggle_debug'), 'vlt_toggle_debug')) . '" class="button">' . ($debug_on ? 'Išjungti debug' : 'Įjungti debug') . '</a>';
        echo '<p class="description">Nustato slapuką vlt_debug_cache šiai sesijai.</p></td></tr>';

        echo '<tr><th>Užklausų registravimas</th><td><label><input type="checkbox" name="vlt_cm_logging" value="1"' . checked($logging, true, false) . '> Įjungtas</label></td></tr>';
        echo '<tr><th>Cloudflare stebėjimas</th><td><label><input type="checkbox" name="vlt_cm_cf_tracking" value="1"' . checked($cf_track, true, false) . '> Įjungtas</label></td></tr>';
        echo '<tr><th>Žurnalų saugojimas (dienų)</th><td><input type="number" name="vlt_cm_log_days" value="' . $log_days . '" min="1" max="365" style="width:80px"></td></tr>';

        $logPath   = get_option('vlt_cm_log_path', WP_CONTENT_DIR . '/uploads/vlt-cache-logs');
        $tracePath = get_option('vlt_cm_trace_path', WP_CONTENT_DIR . '/uploads/vlt-traces');
        echo '<tr><th>Žurnalų kelias</th><td><input type="text" name="vlt_cm_log_path" value="' . esc_attr($logPath) . '" class="regular-text"><p class="description">Talpyklos žurnalų saugojimo vieta</p></td></tr>';
        echo '<tr><th>Pėdsakų kelias</th><td><input type="text" name="vlt_cm_trace_path" value="' . esc_attr($tracePath) . '" class="regular-text"><p class="description">Tracer pėdsakų saugojimo vieta</p></td></tr>';

        $logMaxMb   = (int) get_option('vlt_cm_log_max_mb', 500);
        $traceMaxMb = (int) get_option('vlt_cm_trace_max_mb', 200);
        echo '<tr><th>Max žurnalų dydis (MB)</th><td><input type="number" name="vlt_cm_log_max_mb" value="' . $logMaxMb . '" min="0" max="10000" style="width:80px"><p class="description">0 = neribota. Seniausi failai trinami viršijus limitą.</p></td></tr>';
        echo '<tr><th>Max pėdsakų dydis (MB)</th><td><input type="number" name="vlt_cm_trace_max_mb" value="' . $traceMaxMb . '" min="0" max="10000" style="width:80px"><p class="description">0 = neribota. Seniausi failai trinami viršijus limitą.</p></td></tr>';

        echo '</table>';
        echo '<p class="submit"><button class="button button-primary" type="submit">Išsaugoti nustatymus</button></p>';
        echo '</form>';

        echo '<h2>Veiksmai</h2><p>';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=vlt-cache-settings&action=vlt_download_logs'), 'vlt_download_logs')) . '" class="button">Atsisiųsti žurnalus (ZIP)</a> ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_install_dropin'), 'vlt_install_dropin')) . '" class="button">' . ($dropin_ok ? 'Perdiegti object-cache.php' : 'Įdiegti object-cache.php') . '</a>';
        echo '</p>';

        // Log files section
        $this->renderLogFiles();

        echo '</div>';
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
