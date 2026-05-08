<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class RedisExplorerPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-redis'; }
    public function title(): string { return 'Redis Explorer'; }

    public function render(): void
    {
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
        <script>
        function secOpen(key){try{return localStorage.getItem('vlt_redis_'+key)!=='0'}catch(e){return true}}
        function secSave(key,v){try{localStorage.setItem('vlt_redis_'+key,v?'1':'0')}catch(e){}}
        </script>
        <div class="wrap" x-data="vltRedis()" x-init="init()" x-cloak>
        <h1 class="tw-text-2xl tw-font-bold tw-mb-4">Podėlio Valdymas — Redis naršyklė</h1>

        <!-- Stats + charts in one grid -->
        <div class="tw-mb-4" x-data="{open:secOpen('stats')}" x-init="$watch('open',v=>secSave('stats',v))">
            <h3 class="tw-text-sm tw-font-semibold tw-cursor-pointer tw-flex tw-items-center tw-gap-2 tw-mb-2" @click="open=!open"><span x-text="open?'▾':'▸'"></span> Statistika ir grafikai</h3>
            <div x-show="open">
                <div class="tw-grid tw-gap-3" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
                <!-- Stat cards -->
                <template x-for="c in cards" :key="c.label">
                    <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4">
                        <div class="tw-text-xs tw-text-gray-500" x-text="c.label"></div>
                        <div class="tw-text-2xl tw-font-bold tw-mt-1" x-text="c.value"></div>
                        <div class="tw-text-xs tw-text-gray-400 tw-mt-0.5" x-text="c.sub" x-show="c.sub"></div>
                    </div>
                </template>
                <!-- Groups chart card — spans 2 cols -->
                <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4" style="grid-column:span 2;min-width:0">
                    <div class="tw-flex tw-justify-between tw-items-center tw-mb-2">
                        <span class="tw-text-xs tw-text-gray-500">Raktų pasiskirstymas</span>
                        <button @click="openChartModal('groups')" class="tw-text-xs tw-text-blue-600 tw-cursor-pointer" title="Padidinti">⤢</button>
                    </div>
                    <canvas id="vlt-redis-groups-chart" height="160" class="tw-cursor-pointer" @click="openChartModal('groups')"></canvas>
                </div>
                <!-- Memory chart card — spans 2 cols -->
                <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4" style="grid-column:span 2;min-width:0">
                    <div class="tw-flex tw-justify-between tw-items-center tw-mb-2">
                        <span class="tw-text-xs tw-text-gray-500">Atminties naudojimas</span>
                        <button @click="openChartModal('memory')" class="tw-text-xs tw-text-blue-600 tw-cursor-pointer" title="Padidinti">⤢</button>
                    </div>
                    <canvas id="vlt-redis-memory-chart" height="160" class="tw-cursor-pointer" @click="openChartModal('memory')"></canvas>
                </div>
                </div>
            </div>
        </div>

        <!-- Chart zoom modal -->
        <div x-show="chartModal" class="tw-fixed tw-inset-0 tw-bg-black/60 tw-z-[99999] tw-flex tw-items-center tw-justify-center tw-p-4" @click.self="chartModal=null" style="display:none">
            <div class="tw-bg-white tw-rounded-xl tw-shadow-2xl tw-p-6 tw-w-full tw-max-w-3xl">
                <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                    <h3 class="tw-font-semibold tw-text-base" x-text="chartModal==='groups'?'Raktų pasiskirstymas pagal grupę':'Atminties naudojimas'"></h3>
                    <button @click="chartModal=null" class="tw-text-gray-400 hover:tw-text-gray-700 tw-text-xl tw-leading-none">✕</button>
                </div>
                <canvas id="vlt-redis-modal-chart" height="350"></canvas>
                <div class="tw-mt-3 tw-text-xs tw-text-gray-400 tw-text-center">Spustelėkite už lango ribų arba ✕ kad uždarytumėte</div>
            </div>
        </div>

        <!-- Groups - collapsible -->
        <!-- Groups + Browse side-by-side -->
        <div class="tw-flex tw-gap-4 tw-items-start tw-mb-4">

        <!-- Groups list -->
        <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4" :class="browsing ? 'tw-w-1/2' : 'tw-w-full'" x-data="{open:secOpen('groups')}" x-init="$watch('open',v=>secSave('groups',v))" style="transition:width .3s ease;min-width:0">
            <h3 class="tw-font-semibold tw-mb-3 tw-cursor-pointer tw-flex tw-items-center tw-gap-2" @click="open=!open"><span x-text="open?'▾':'▸'"></span> Grupės</h3>
            <div x-show="open" class="tw-overflow-x-auto">
            <table class="tw-w-full tw-text-sm">
                <thead class="tw-bg-gray-50"><tr>
                    <th class="tw-px-3 tw-py-2 tw-text-left tw-cursor-pointer" @click="groupSort='name';groupDir=groupDir==='asc'?'desc':'asc'">Grupė <span x-text="groupSort==='name'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-24 tw-cursor-pointer" @click="groupSort='count';groupDir=groupDir==='asc'?'desc':'asc'">Raktų <span x-text="groupSort==='count'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-24 tw-cursor-pointer" @click="groupSort='size';groupDir=groupDir==='asc'?'desc':'asc'">Dydis <span x-text="groupSort==='size'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-32">Veiksmai</th>
                </tr></thead>
                <tbody>
                    <template x-for="g in sortedGroups" :key="g.name">
                        <tr class="tw-border-b tw-border-gray-50 hover:tw-bg-gray-50" :class="browseGroupName===g.name&&browsing?'tw-bg-blue-50':''">
                            <td class="tw-px-3 tw-py-2 tw-font-semibold" x-text="g.name"></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right" x-text="g.count"></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right" x-text="formatSize(g.size)"></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right tw-space-x-2">
                                <a href="#" @click.prevent="browseGroup(g.name)" class="tw-text-blue-600 tw-hover:underline tw-text-xs" :class="browseGroupName===g.name&&browsing?'tw-font-bold':''">Naršyti</a>
                                <a href="#" @click.prevent="if(confirm('Ištrinti visus '+g.name+' raktus?'))deleteGroup(g.name)" class="tw-text-red-600 tw-hover:underline tw-text-xs">Trinti</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Browse panel — slides in on the right -->
        <div x-show="browsing" x-transition:enter="tw-transition tw-ease-out tw-duration-200" x-transition:enter-start="tw-opacity-0 tw-translate-x-4" x-transition:enter-end="tw-opacity-100 tw-translate-x-0" class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4 tw-w-1/2" style="min-width:0" x-cloak>
            <div class="tw-flex tw-flex-wrap tw-justify-between tw-items-center tw-mb-3 tw-gap-2">
                <h3 class="tw-font-semibold">Grupė: <span x-text="browseGroupName"></span> (<span x-text="browseKeys.length"></span> raktų)</h3>
                <div class="tw-flex tw-gap-2 tw-items-center">
                    <input type="text" x-model="keySearch" @input="filterKeys()" placeholder="Ieškoti rakto..." class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm tw-w-48">
                    <button @click="browsing=false" class="tw-bg-gray-200 tw-hover:bg-gray-300 tw-px-3 tw-py-1 tw-rounded tw-text-sm">Uždaryti</button>
                </div>
            </div>
            <div class="tw-overflow-x-auto">
            <table class="tw-w-full tw-text-sm">
                <thead class="tw-bg-gray-50"><tr>
                    <th class="tw-px-3 tw-py-2 tw-text-left">Raktas</th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-20">Dydis</th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-20">TTL</th>
                    <th class="tw-px-3 tw-py-2 tw-text-right w-40">Veiksmai</th>
                </tr></thead>
                <tbody>
                    <template x-for="k in pagedKeys" :key="k.key">
                        <tr class="tw-border-b tw-border-gray-50 hover:tw-bg-gray-50">
                            <td class="tw-px-3 tw-py-2"><code class="tw-text-xs tw-break-all" x-text="k.key"></code></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right" x-text="formatSize(k.size)"></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right" x-text="k.ttl===-1?'∞':k.ttl+'s'"></td>
                            <td class="tw-px-3 tw-py-2 tw-text-right tw-space-x-2">
                                <a href="#" @click.prevent="previewKey(k.key)" class="tw-text-blue-600 tw-hover:underline tw-text-xs">Peržiūrėti</a>
                                <a href="#" @click.prevent="if(confirm('Ištrinti?'))deleteKey(k.key)" class="tw-text-red-600 tw-hover:underline tw-text-xs">Trinti</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
            <div class="tw-flex tw-justify-between tw-items-center tw-mt-2" x-show="filteredKeys.length>keyPerPage">
                <span class="tw-text-sm tw-text-gray-500">Puslapis <strong x-text="keyPage"></strong> / <strong x-text="Math.ceil(filteredKeys.length/keyPerPage)"></strong></span>
                <div class="tw-flex tw-gap-1">
                    <button @click="keyPage=Math.max(1,keyPage-1)" :disabled="keyPage<=1" class="tw-bg-gray-200 tw-hover:bg-gray-300 tw-disabled:opacity-50 tw-px-2 tw-py-1 tw-rounded tw-text-sm">←</button>
                    <button @click="keyPage=Math.min(Math.ceil(filteredKeys.length/keyPerPage),keyPage+1)" :disabled="keyPage>=Math.ceil(filteredKeys.length/keyPerPage)" class="tw-bg-gray-200 tw-hover:bg-gray-300 tw-disabled:opacity-50 tw-px-2 tw-py-1 tw-rounded tw-text-sm">→</button>
                </div>
            </div>
        </div>

        </div><!-- end groups+browse flex -->

        <div x-show="previewVisible" class="tw-fixed tw-inset-0 tw-bg-black/50 tw-z-[100000] tw-flex tw-items-center tw-justify-center" @click.self="previewVisible=false">
            <div class="tw-bg-white tw-rounded-lg w-11/12 tw-max-w-3xl tw-max-h-[80vh] tw-overflow-auto tw-p-5">
                <div class="tw-flex tw-justify-between tw-items-start tw-mb-3">
                    <h3 class="tw-font-semibold tw-text-sm tw-break-all tw-pr-4" x-text="previewData.key"></h3>
                    <button @click="previewVisible=false" class="tw-bg-gray-200 tw-hover:bg-gray-300 tw-px-2 tw-py-0.5 tw-rounded tw-text-sm tw-shrink-0">✕</button>
                </div>
                <div class="tw-grid tw-grid-cols-4 tw-gap-2 tw-text-sm tw-mb-3 tw-bg-gray-50 tw-rounded tw-p-3">
                    <div><span class="tw-text-gray-500">Tipas:</span> <span x-text="previewData.type"></span></div>
                    <div><span class="tw-text-gray-500">TTL:</span> <span x-text="previewData.ttl===-1?'Neribotas':previewData.ttl+' sek.'"></span></div>
                    <div><span class="tw-text-gray-500">Dydis:</span> <span x-text="formatSize(previewData.size)"></span></div>
                    <div><span class="tw-text-gray-500">Serializuota:</span> <span x-text="previewData.serialized?'Taip':'Ne'"></span></div>
                </div>
                <div class="tw-flex tw-gap-2 tw-mb-2">
                    <button :class="previewMode==="pretty'?'bg-blue-600 text-white':'bg-gray-200'" @click="previewMode='pretty'" class="tw-px-3 tw-py-1 tw-rounded tw-text-sm">Struktūra</button>
                    <button :class="previewMode==="raw'?'bg-blue-600 text-white':'bg-gray-200'" @click="previewMode='raw'" class="tw-px-3 tw-py-1 tw-rounded tw-text-sm">Neapdorotas</button>
                </div>
                <pre x-show="previewMode==='raw'" class="tw-bg-gray-900 text-gray-200 tw-p-3 tw-rounded tw-overflow-auto tw-max-h-96 tw-text-xs tw-whitespace-pre-wrap tw-break-all" x-text="previewData.raw"></pre>
                <pre x-show="previewMode==='pretty'" class="tw-bg-gray-50 tw-p-3 tw-rounded tw-overflow-auto tw-max-h-96 tw-text-xs tw-whitespace-pre-wrap tw-break-all" x-text="previewData.pretty"></pre>
                <div class="tw-mt-3 tw-text-right">
                    <button @click="if(confirm('Ištrinti šį raktą?')){deleteKey(previewData.key);previewVisible=false}" class="tw-text-red-600 tw-hover:underline tw-text-sm">Ištrinti raktą</button>
                </div>
            </div>
        </div>
        </div>

        <script>
        function secOpen(key){try{return localStorage.getItem('vlt_redis_'+key)!=='0'}catch(e){return true}}
        function secSave(key,v){try{localStorage.setItem('vlt_redis_'+key,v?'1':'0')}catch(e){}}
        document.addEventListener('alpine:init', () => {
        Alpine.data('vltRedis', () => ({
                cards: [], groups: [], groupSort: 'count', groupDir: 'desc',
                browsing: false, browseGroupName: '', browseKeys: [], filteredKeys: [], keySearch: '', keyPage: 1, keyPerPage: 50,
                previewVisible: false, previewData: {}, previewMode: 'pretty',
                charts: {}, pollTimer: null, chartModal: null, modalChart: null, lastStats: null,

                async init() {
                    this.$watch('chartModal', v => {
                        if (!v && this.modalChart) { this.modalChart.destroy(); this.modalChart = null; }
                    });
                    await this.fetchStats();
                    this.pollTimer = setInterval(() => this.fetchStats(), 10000);
                },

                async fetchStats() {
                    const d = await this.api('stats');
                    if (!d) { console.warn('VLT Redis: fetchStats returned no data'); return; }
                    this.cards = [
                        {label:'Prisijungimas', value:d.connected?'Prijungtas':'Nepasiekiamas', sub:''},
                        {label:'Atmintis', value:d.memory, sub:d.memory_peak?'Pikas: '+d.memory_peak:''},
                        {label:'Raktų', value:d.keys.toLocaleString(), sub:'DB0'},
                        {label:'Pataikymų santykis', value:d.hit_rate+'%', sub:d.hits.toLocaleString()+' / '+(d.hits+d.misses).toLocaleString()},
                        {label:'Pataikymai', value:d.hits.toLocaleString(), sub:''},
                        {label:'Praleidimai', value:d.misses.toLocaleString(), sub:''},
                        {label:'Pasibaigę', value:d.expired.toLocaleString(), sub:''},
                        {label:'Veikimo laikas', value:d.uptime, sub:''},
                    ];
                    this.groups = d.groups || [];
                    this.lastStats = d;
                    this.$nextTick(() => this.waitForChart(() => this.drawCharts(d)));
                },

                waitForChart(cb, attempts) {
                    attempts = attempts || 0;
                    if (typeof Chart !== 'undefined') { cb(); return; }
                    if (attempts > 50) { console.error('VLT Redis: Chart.js failed to load after 5s'); return; }
                    setTimeout(() => this.waitForChart(cb, attempts + 1), 100);
                },

                drawCharts(d) {
                    try {
                    const top = (d.groups||[]).slice(0,10);
                    const other = (d.groups||[]).slice(10).reduce((s,g)=>s+g.count,0);
                    const labels = top.map(g=>g.name);
                    const data = top.map(g=>g.count);
                    if (other) { labels.push('kita'); data.push(other); }
                    const colors = ['#3b82f6','#ef4444','#22c55e','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16','#94a3b8'];

                    const gc = document.getElementById('vlt-redis-groups-chart');
                    const mc = document.getElementById('vlt-redis-memory-chart');
                    // Canvas hidden by x-show — retry after section becomes visible (max 10 attempts)
                    if (!gc || !mc || gc.offsetParent === null) {
                        if ((d._retries || 0) < 10) {
                            d._retries = (d._retries || 0) + 1;
                            setTimeout(() => this.drawCharts(d), 400);
                        }
                        return;
                    }

                    if (this.charts.groups) this.charts.groups.destroy();
                    this.charts.groups = new Chart(gc, {
                        type:'doughnut', data:{labels, datasets:[{data, backgroundColor:colors}]},
                        options:{responsive:true, plugins:{legend:{position:'right',labels:{font:{size:11}}}}}
                    });

                    if (this.charts.memory) this.charts.memory.destroy();
                    const used = parseFloat(d.memory)||0, peak = parseFloat(d.memory_peak)||0;
                    this.charts.memory = new Chart(mc, {
                        type:'bar',
                        data:{labels:['Naudojama','Pikas'], datasets:[{data:[used,peak], backgroundColor:['#3b82f6','#94a3b8'], barThickness:40}]},
                        options:{responsive:true, scales:{y:{beginAtZero:true,title:{display:true,text:'MB'}}}, plugins:{legend:{display:false}}}
                    });
                    } catch(e) { console.error('VLT Redis drawCharts error:', e); }
                },

                openChartModal(type) {
                    this.chartModal = type;
                    this.$nextTick(() => {
                        const mc = document.getElementById('vlt-redis-modal-chart');
                        if (!mc) return;
                        if (this.modalChart) { this.modalChart.destroy(); this.modalChart = null; }
                        const src = this.charts[type];
                        if (!src) return;
                        // Clone config with legend enabled and larger display
                        const cfg = JSON.parse(JSON.stringify(src.config));
                        if (cfg.options?.plugins?.legend) cfg.options.plugins.legend.display = true;
                        if (cfg.options?.plugins?.legend) cfg.options.plugins.legend.position = 'bottom';
                        cfg.options = cfg.options || {};
                        cfg.options.responsive = true;
                        cfg.options.maintainAspectRatio = false;
                        mc.style.height = '350px';
                        this.modalChart = new Chart(mc, cfg);
                    });
                },

                get sortedGroups() {
                    return [...this.groups].sort((a,b) => {
                        const va=a[this.groupSort], vb=b[this.groupSort];
                        const cmp = typeof va==='number'?va-vb:String(va).localeCompare(String(vb));
                        return this.groupDir==='asc'?cmp:-cmp;
                    });
                },

                async browseGroup(name) {
                    this.browseGroupName = name; this.keySearch = ''; this.keyPage = 1;
                    const d = await this.api('keys', {group:name});
                    if (d) { this.browseKeys = d; this.filteredKeys = d; this.browsing = true; }
                },

                filterKeys() {
                    const s = this.keySearch.toLowerCase();
                    this.filteredKeys = s ? this.browseKeys.filter(k=>k.key.toLowerCase().includes(s)) : this.browseKeys;
                    this.keyPage = 1;
                },

                get pagedKeys() { return this.filteredKeys.slice((this.keyPage-1)*this.keyPerPage, this.keyPage*this.keyPerPage); },

                async previewKey(key) {
                    const d = await this.api('preview', {key});
                    if (d) { this.previewData = d; this.previewMode = 'pretty'; this.previewVisible = true; }
                },

                async deleteKey(key) {
                    await this.api('delete', {key});
                    this.browseKeys = this.browseKeys.filter(k=>k.key!==key);
                    this.filterKeys();
                    this.fetchStats();
                },

                async deleteGroup(name) {
                    await this.api('delete_group', {group:name});
                    this.browsing = false;
                    this.fetchStats();
                },

                async api(action, params={}) {
                    const p = new URLSearchParams(params);
                    try {
                        const r = await fetch('<?php echo $rest_url; ?>/redis/'+action+'?'+p, {headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});
                        const d = await r.json();
                        return d.error ? null : d;
                    } catch(e) { console.error(e); return null; }
                },

                formatSize(b) {
                    if (b>=1048576) return (b/1048576).toFixed(1)+' MB';
                    if (b>=1024) return (b/1024).toFixed(1)+' KB';
                    return b+' B';
                }
            }));
        }); // alpine:init
        </script>
        <?php
    }
}
