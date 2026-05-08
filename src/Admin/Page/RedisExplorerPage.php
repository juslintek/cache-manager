<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class RedisExplorerPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-redis'; }
    public function title(): string { return 'Redis naršyklė'; }

    public function render(): void
    {
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
        <div class="wrap" x-data="vltRedis()" x-init="init()" x-cloak>
        <h1 class="text-2xl font-bold mb-4">Podėlio Valdymas — Redis naršyklė</h1>

        <!-- Stats cards - collapsible -->
        <div class="mb-4" x-data="{open:secOpen('stats')}" x-init="$watch('open',v=>secSave('stats',v))">
            <h3 class="text-sm font-semibold cursor-pointer flex items-center gap-2 mb-2" @click="open=!open"><span x-text="open?'▾':'▸'"></span> Statistika</h3>
            <div x-show="open" class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <template x-for="c in cards" :key="c.label">
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="text-xs text-gray-500" x-text="c.label"></div>
                    <div class="text-2xl font-bold mt-1" x-text="c.value"></div>
                    <div class="text-xs text-gray-400 mt-0.5" x-text="c.sub" x-show="c.sub"></div>
                </div>
            </template>
            </div>
        </div>

        <!-- Charts - collapsible -->
        <div class="mb-4" x-data="{open:secOpen('charts')}" x-init="$watch('open',v=>secSave('charts',v))">
            <h3 class="text-sm font-semibold cursor-pointer flex items-center gap-2 mb-2" @click="open=!open"><span x-text="open?'▾':'▸'"></span> Grafikai</h3>
            <div x-show="open" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="font-semibold mb-3">Raktų pasiskirstymas pagal grupę</h3>
                <canvas id="vlt-redis-groups-chart" height="250"></canvas>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="font-semibold mb-3">Atminties naudojimas</h3>
                <canvas id="vlt-redis-memory-chart" height="250"></canvas>
            </div>
        </div>

        <!-- Groups - collapsible -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4" x-data="{open:secOpen('groups')}" x-init="$watch('open',v=>secSave('groups',v))">
            <h3 class="font-semibold mb-3 cursor-pointer flex items-center gap-2" @click="open=!open"><span x-text="open?'▾':'▸'"></span> Grupės</h3>
            <div x-show="open" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-3 py-2 text-left cursor-pointer" @click="groupSort='name';groupDir=groupDir==='asc'?'desc':'asc'">Grupė <span x-text="groupSort==='name'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="px-3 py-2 text-right w-24 cursor-pointer" @click="groupSort='count';groupDir=groupDir==='asc'?'desc':'asc'">Raktų <span x-text="groupSort==='count'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="px-3 py-2 text-right w-24 cursor-pointer" @click="groupSort='size';groupDir=groupDir==='asc'?'desc':'asc'">Dydis <span x-text="groupSort==='size'?(groupDir==='asc'?'▲':'▼'):''"></span></th>
                    <th class="px-3 py-2 text-right w-32">Veiksmai</th>
                </tr></thead>
                <tbody>
                    <template x-for="g in sortedGroups" :key="g.name">
                        <tr class="border-b border-gray-50 hover:bg-gray-50">
                            <td class="px-3 py-2 font-semibold" x-text="g.name"></td>
                            <td class="px-3 py-2 text-right" x-text="g.count"></td>
                            <td class="px-3 py-2 text-right" x-text="formatSize(g.size)"></td>
                            <td class="px-3 py-2 text-right space-x-2">
                                <a href="#" @click.prevent="browseGroup(g.name)" class="text-blue-600 hover:underline text-xs">Naršyti</a>
                                <a href="#" @click.prevent="if(confirm('Ištrinti visus '+g.name+' raktus?'))deleteGroup(g.name)" class="text-red-600 hover:underline text-xs">Trinti</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
        </div>

        <div x-show="browsing" class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div class="flex flex-wrap justify-between items-center mb-3 gap-2">
                <h3 class="font-semibold">Grupė: <span x-text="browseGroupName"></span> (<span x-text="browseKeys.length"></span> raktų)</h3>
                <div class="flex gap-2 items-center">
                    <input type="text" x-model="keySearch" @input="filterKeys()" placeholder="Ieškoti rakto..." class="border border-gray-300 rounded px-2 py-1 text-sm w-48">
                    <button @click="browsing=false" class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded text-sm">Uždaryti</button>
                </div>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-3 py-2 text-left">Raktas</th>
                    <th class="px-3 py-2 text-right w-20">Dydis</th>
                    <th class="px-3 py-2 text-right w-20">TTL</th>
                    <th class="px-3 py-2 text-right w-40">Veiksmai</th>
                </tr></thead>
                <tbody>
                    <template x-for="k in pagedKeys" :key="k.key">
                        <tr class="border-b border-gray-50 hover:bg-gray-50">
                            <td class="px-3 py-2"><code class="text-xs break-all" x-text="k.key"></code></td>
                            <td class="px-3 py-2 text-right" x-text="formatSize(k.size)"></td>
                            <td class="px-3 py-2 text-right" x-text="k.ttl===-1?'∞':k.ttl+'s'"></td>
                            <td class="px-3 py-2 text-right space-x-2">
                                <a href="#" @click.prevent="previewKey(k.key)" class="text-blue-600 hover:underline text-xs">Peržiūrėti</a>
                                <a href="#" @click.prevent="if(confirm('Ištrinti?'))deleteKey(k.key)" class="text-red-600 hover:underline text-xs">Trinti</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
            <div class="flex justify-between items-center mt-2" x-show="filteredKeys.length>keyPerPage">
                <span class="text-sm text-gray-500">Puslapis <strong x-text="keyPage"></strong> / <strong x-text="Math.ceil(filteredKeys.length/keyPerPage)"></strong></span>
                <div class="flex gap-1">
                    <button @click="keyPage=Math.max(1,keyPage-1)" :disabled="keyPage<=1" class="bg-gray-200 hover:bg-gray-300 disabled:opacity-50 px-2 py-1 rounded text-sm">←</button>
                    <button @click="keyPage=Math.min(Math.ceil(filteredKeys.length/keyPerPage),keyPage+1)" :disabled="keyPage>=Math.ceil(filteredKeys.length/keyPerPage)" class="bg-gray-200 hover:bg-gray-300 disabled:opacity-50 px-2 py-1 rounded text-sm">→</button>
                </div>
            </div>
        </div>

        <div x-show="previewVisible" class="fixed inset-0 bg-black/50 z-[100000] flex items-center justify-center" @click.self="previewVisible=false">
            <div class="bg-white rounded-lg w-11/12 max-w-3xl max-h-[80vh] overflow-auto p-5">
                <div class="flex justify-between items-start mb-3">
                    <h3 class="font-semibold text-sm break-all pr-4" x-text="previewData.key"></h3>
                    <button @click="previewVisible=false" class="bg-gray-200 hover:bg-gray-300 px-2 py-0.5 rounded text-sm shrink-0">✕</button>
                </div>
                <div class="grid grid-cols-4 gap-2 text-sm mb-3 bg-gray-50 rounded p-3">
                    <div><span class="text-gray-500">Tipas:</span> <span x-text="previewData.type"></span></div>
                    <div><span class="text-gray-500">TTL:</span> <span x-text="previewData.ttl===-1?'Neribotas':previewData.ttl+' sek.'"></span></div>
                    <div><span class="text-gray-500">Dydis:</span> <span x-text="formatSize(previewData.size)"></span></div>
                    <div><span class="text-gray-500">Serializuota:</span> <span x-text="previewData.serialized?'Taip':'Ne'"></span></div>
                </div>
                <div class="flex gap-2 mb-2">
                    <button :class="previewMode==='pretty'?'bg-blue-600 text-white':'bg-gray-200'" @click="previewMode='pretty'" class="px-3 py-1 rounded text-sm">Struktūra</button>
                    <button :class="previewMode==='raw'?'bg-blue-600 text-white':'bg-gray-200'" @click="previewMode='raw'" class="px-3 py-1 rounded text-sm">Neapdorotas</button>
                </div>
                <pre x-show="previewMode==='raw'" class="bg-gray-900 text-gray-200 p-3 rounded overflow-auto max-h-96 text-xs whitespace-pre-wrap break-all" x-text="previewData.raw"></pre>
                <pre x-show="previewMode==='pretty'" class="bg-gray-50 p-3 rounded overflow-auto max-h-96 text-xs whitespace-pre-wrap break-all" x-text="previewData.pretty"></pre>
                <div class="mt-3 text-right">
                    <button @click="if(confirm('Ištrinti šį raktą?')){deleteKey(previewData.key);previewVisible=false}" class="text-red-600 hover:underline text-sm">Ištrinti raktą</button>
                </div>
            </div>
        </div>
        </div>

        <script>
        function secOpen(key){try{return localStorage.getItem('vlt_redis_'+key)!=='0'}catch(e){return true}}
        function secSave(key,v){try{localStorage.setItem('vlt_redis_'+key,v?'1':'0')}catch(e){}}
        function vltRedis() {
            return {
                cards: [], groups: [], groupSort: 'count', groupDir: 'desc',
                browsing: false, browseGroupName: '', browseKeys: [], filteredKeys: [], keySearch: '', keyPage: 1, keyPerPage: 50,
                previewVisible: false, previewData: {}, previewMode: 'pretty',
                charts: {}, pollTimer: null,

                async init() {
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
                    if (!gc || !mc) { console.warn('VLT Redis: canvas elements not found'); return; }

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
            };
        }
        </script>
        <?php
    }
}
