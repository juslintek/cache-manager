<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class OpcacheExplorerPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-opcache'; }
    public function title(): string { return 'OPcache'; }

    public function render(): void
    {
        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
        $config = function_exists('opcache_get_configuration') ? opcache_get_configuration() : null;
        ?>
        <div class="wrap tw-text-sm" x-data="vltOpc()" x-cloak>
        <h1 class="tw-text-2xl tw-font-bold tw-mb-4">Podėlio Valdymas — OPcache</h1>

        <?php if (!$status): ?>
            <div class="tw-bg-red-50 tw-border tw-border-red-200 tw-rounded tw-p-4 text-red-700">OPcache išjungtas arba nepasiekiamas.</div>
        <?php else:
            $mem = $status['memory_usage'] ?? [];
            $stats = $status['opcache_statistics'] ?? [];
            $hit_rate = round($stats['opcache_hit_rate'] ?? 0, 1);
            $used_mem = round(($mem['used_memory'] ?? 0) / 1048576, 1);
            $free_mem = round(($mem['free_memory'] ?? 0) / 1048576, 1);
            $wasted = round(($mem['wasted_memory'] ?? 0) / 1048576, 1);
            $wasted_pct = round(($mem['current_wasted_percentage'] ?? 0), 1);
            $scripts = $stats['num_cached_scripts'] ?? 0;
            $keys = $stats['num_cached_keys'] ?? 0;
            $max_keys = $stats['max_cached_keys'] ?? 0;
            $hits = $stats['hits'] ?? 0;
            $misses = $stats['misses'] ?? 0;
            $restarts = ($stats['oom_restarts'] ?? 0) + ($stats['hash_restarts'] ?? 0) + ($stats['manual_restarts'] ?? 0);
        ?>

        <!-- Stats cards -->
        <div class="tw-grid tw-grid-cols-2 tw-md:grid-cols-4 tw-gap-3 tw-mb-4">
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
                <div class="tw-text-xs tw-text-gray-500">Pataikymų santykis</div>
                <div class="tw-text-2xl tw-font-bold tw-mt-1 <?php echo $hit_rate > 95 ?'text-green-600' : ($hit_rate > 80 ? 'text-yellow-600' : 'text-red-600'); ?>"><?php echo $hit_rate; ?>%</div>
                <div class="tw-text-xs tw-text-gray-400"><?php echo number_format($hits); ?> / <?php echo number_format($hits + $misses); ?></div>
            </div>
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
                <div class="tw-text-xs tw-text-gray-500">Atmintis</div>
                <div class="tw-text-2xl tw-font-bold tw-mt-1"><?php echo $used_mem; ?> MB</div>
                <div class="tw-text-xs tw-text-gray-400">Laisva: <?php echo $free_mem; ?> MB | Švaistyma: <?php echo $wasted; ?> MB (<?php echo $wasted_pct; ?>%)</div>
            </div>
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
                <div class="tw-text-xs tw-text-gray-500">Talpykloje failų</div>
                <div class="tw-text-2xl tw-font-bold tw-mt-1"><?php echo number_format($scripts); ?></div>
                <div class="tw-text-xs tw-text-gray-400">Raktai: <?php echo number_format($keys); ?> / <?php echo number_format($max_keys); ?></div>
            </div>
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
                <div class="tw-text-xs tw-text-gray-500">Perkrovimai</div>
                <div class="tw-text-2xl tw-font-bold tw-mt-1 <?php echo $restarts > 0 ?'text-red-600' : 'text-green-600'; ?>"><?php echo $restarts; ?></div>
                <div class="tw-text-xs tw-text-gray-400">OOM: <?php echo $stats['oom_restarts'] ?? 0; ?> | Hash: <?php echo $stats['hash_restarts'] ?? 0; ?> | Rankiniai: <?php echo $stats['manual_restarts'] ?? 0; ?></div>
            </div>
        </div>

        <!-- Memory bar -->
        <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4 tw-mb-4">
            <h3 class="tw-text-xs tw-font-semibold tw-mb-2">Atminties naudojimas</h3>
            <div class="tw-h-6 tw-rounded tw-overflow-hidden tw-flex">
                <div class="tw-bg-blue-500 tw-h-full" style="width:<?php echo round($used_mem / ($used_mem + $free_mem + $wasted) * 100); ?>%" title="Naudojama: <?php echo $used_mem; ?> MB"></div>
                <div class="tw-bg-red-300 tw-h-full" style="width:<?php echo round($wasted / ($used_mem + $free_mem + $wasted) * 100); ?>%" title="Švaistyma: <?php echo $wasted; ?> MB"></div>
                <div class="tw-bg-green-200 tw-h-full tw-flex-1" title="Laisva: <?php echo $free_mem; ?> MB"></div>
            </div>
            <div class="tw-flex tw-gap-4 tw-mt-2 tw-text-xs tw-text-gray-500">
                <span class="tw-flex tw-items-center tw-gap-1"><span class="w-3 tw-h-3 tw-bg-blue-500 tw-rounded"></span> Naudojama</span>
                <span class="tw-flex tw-items-center tw-gap-1"><span class="w-3 tw-h-3 tw-bg-red-300 tw-rounded"></span> Švaistyma</span>
                <span class="tw-flex tw-items-center tw-gap-1"><span class="w-3 tw-h-3 tw-bg-green-200 tw-rounded"></span> Laisva</span>
            </div>
        </div>

        <!-- Configuration -->
        <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4 tw-mb-4" x-data="{show:false}">
            <h3 class="tw-text-xs tw-font-semibold tw-cursor-pointer tw-flex tw-items-center tw-gap-2" @click="show=!show">
                <span x-text="show?'▾':'▸'"></span> Konfigūracija
            </h3>
            <div x-show="show" class="tw-mt-2">
                <table class="tw-w-full tw-text-xs">
                    <?php if ($config): foreach ($config['directives'] ?? [] as $k => $v): ?>
                    <tr class="tw-border-b tw-border-gray-50"><td class="tw-py-1 tw-text-gray-600 tw-w-64"><?php echo esc_html($k); ?></td><td class="tw-py-1 tw-font-mono"><?php echo esc_html(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v); ?></td></tr>
                    <?php endforeach; endif; ?>
                </table>
            </div>
        </div>

        <!-- Cached scripts -->
        <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                <h3 class="tw-text-xs tw-font-semibold">Talpykloje esantys failai (<?php echo number_format($scripts); ?>)</h3>
                <input type="text" class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-xs tw-w-64" placeholder="Filtruoti pagal kelią..." x-model="filter">
            </div>
            <div class="tw-overflow-auto tw-max-h-96">
                <table class="tw-w-full tw-text-[10px]">
                    <thead class="tw-bg-gray-50 tw-sticky tw-top-0"><tr>
                        <th class="tw-px-2 tw-py-1 tw-text-left tw-cursor-pointer" @click="sort='path';dir=dir==='asc'?'desc':'asc'">Failas</th>
                        <th class="tw-px-2 tw-py-1 tw-text-right tw-w-16 tw-cursor-pointer" @click="sort='hits';dir=dir==='asc'?'desc':'asc'">Pataikymai</th>
                        <th class="tw-px-2 tw-py-1 tw-text-right tw-w-16 tw-cursor-pointer" @click="sort='memory';dir=dir==='asc'?'desc':'asc'">Dydis</th>
                        <th class="tw-px-2 tw-py-1 tw-text-right tw-w-32 tw-cursor-pointer" @click="sort='timestamp';dir=dir==='asc'?'desc':'asc'">Paskutinis</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="s in filteredScripts" :key="s.path">
                            <tr class="tw-border-b tw-border-gray-50 tw-hover:tw-bg-gray-50">
                                <td class="tw-px-2 tw-py-0.5 tw-truncate tw-max-w-md" :title="s.path" x-text="s.path"></td>
                                <td class="tw-px-2 tw-py-0.5 tw-text-right tw-font-mono" x-text="s.hits"></td>
                                <td class="tw-px-2 tw-py-0.5 tw-text-right tw-font-mono" x-text="sz(s.memory)"></td>
                                <td class="tw-px-2 tw-py-0.5 tw-text-right tw-text-gray-400" x-text="new Date(s.timestamp*1000).toLocaleString('lt')"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Purge button -->
        <p class="tw-mt-4">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=vlt_purge&type=opcache'), 'vlt_purge')); ?>" class="button">Valyti OPcache</a>
        </p>

        <?php endif; ?>
        </div>

        <script>
        function vltOpc(){return{
            filter:'',sort:'hits',dir:'desc',
            scripts:<?php echo json_encode(array_values(array_map(function($s) {
                return ['path' => $s['full_path'], 'hits' => $s['hits'], 'memory' => $s['memory_consumption'], 'timestamp' => $s['last_used_timestamp']];
            }, $status ? (opcache_get_status(true)['scripts'] ?? []) : []))); ?>,
            get filteredScripts(){
                let s=this.scripts;
                if(this.filter)s=s.filter(x=>x.path.toLowerCase().includes(this.filter.toLowerCase()));
                return s.sort((a,b)=>{const cmp=typeof a[this.sort]==='number'?a[this.sort]-b[this.sort]:a[this.sort].localeCompare(b[this.sort]);return this.dir==='asc'?cmp:-cmp}).slice(0,500);
            },
            sz(b){if(b>=1048576)return(b/1048576).toFixed(1)+'MB';if(b>=1024)return(b/1024).toFixed(0)+'KB';return b+'B'}
        }}
        </script>
        <?php
    }
}
