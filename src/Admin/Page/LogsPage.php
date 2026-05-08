<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class LogsPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-logs'; }
    public function title(): string { return 'Žurnalai'; }

    public function render(): void
    {
        $date     = sanitize_text_field($_GET['log_date'] ?? gmdate('Y-m-d'));
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        $sse_url  = esc_js(rest_url('vlt-cache/v1/logs/stream') . '?_wpnonce=' . $nonce);
        ?>
        <div class="wrap" x-data="vltLogs()" x-init="init()" x-cloak>
        <h1 class="text-2xl font-bold mb-4">Podėlio Valdymas — Žurnalai</h1>

        <!-- Filters -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4 grid grid-cols-2 md:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-semibold mb-1">Data</label>
                <input type="date" x-model="filters.date" @change="fullFetch()" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Tipas</label>
                <select x-model="filters.type" @change="fullFetch()" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                    <option value="">Visi</option>
                    <option value="purge">Valymas</option>
                    <option value="stats">Statistika</option>
                    <option value="cloudflare">Cloudflare</option>
                    <option value="error">Klaidos</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">IP</label>
                <input type="text" x-model.debounce.300ms="filters.ip" @input="fullFetch()" placeholder="pvz. 84.15" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
            </div>
            <div class="relative">
                <label class="block text-xs font-semibold mb-1">URL</label>
                <input type="text" x-model="filters.uri" @input="onUriInput()" @focus="showSuggest=true" @blur="setTimeout(()=>showSuggest=false,200)" placeholder="pvz. /naujienos" class="w-full border border-gray-300 rounded px-2 py-1 text-sm" autocomplete="off">
                <div x-show="showSuggest && suggestions.length" class="absolute z-50 bg-white border border-gray-200 shadow-lg rounded mt-1 max-h-48 overflow-auto w-full">
                    <template x-for="s in suggestions" :key="s">
                        <div @mousedown.prevent="filters.uri=s;showSuggest=false;fullFetch()" class="px-2 py-1 text-xs cursor-pointer hover:bg-blue-50" x-text="s"></div>
                    </template>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1">Vartotojas</label>
                <input type="text" x-model.debounce.300ms="filters.user" @input="fullFetch()" placeholder="vardas / ID" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
            </div>
        </div>

        <!-- Group by -->
        <div class="flex flex-wrap gap-3 items-center mb-3 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm">
            <span class="font-semibold">Grupuoti pagal:</span>
            <template x-for="g in groupOptions" :key="g.key">
                <label class="flex items-center gap-1 cursor-pointer">
                    <input type="checkbox" :value="g.key" x-model="groupFields" @change="fullFetch()">
                    <span x-text="g.label"></span>
                </label>
            </template>
        </div>

        <!-- Stats bar -->
        <div class="flex gap-4 mb-3 text-sm" x-show="meta.total>0">
            <span><strong x-text="meta.total"></strong> įrašų</span>
            <span>Pataikymai: <strong x-text="meta.totalHits"></strong></span>
            <span>Praleidimai: <strong x-text="meta.totalMisses"></strong></span>
            <span>Santykis: <strong x-text="meta.ratio+'%'"></strong></span>
        </div>

        <div x-show="loading" class="py-8 text-center text-gray-500">Kraunama...</div>

        <!-- Grouped view -->
        <template x-if="groupFields.length > 0 && !loading">
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-3 py-2 text-left w-8"></th>
                    <th class="px-3 py-2 text-left cursor-pointer" @click="toggleSort('label')">Grupė <span x-text="sortIcon('label')"></span></th>
                    <th class="px-3 py-2 text-right w-20 cursor-pointer" @click="toggleSort('count')">Įrašų <span x-text="sortIcon('count')"></span></th>
                    <th class="px-3 py-2 text-right w-24 cursor-pointer" @click="toggleSort('hits')">Pataikymai <span x-text="sortIcon('hits')"></span></th>
                    <th class="px-3 py-2 text-right w-24 cursor-pointer" @click="toggleSort('misses')">Praleidimai <span x-text="sortIcon('misses')"></span></th>
                    <th class="px-3 py-2 text-right w-20 cursor-pointer" @click="toggleSort('purges')">Valymai <span x-text="sortIcon('purges')"></span></th>
                </tr></thead>
                <tbody>
                    <template x-for="(row, idx) in sortedRows" :key="idx">
                        <tr>
                            <td colspan="6" class="p-0">
                                <div class="flex items-center px-3 py-2 border-b border-gray-100 cursor-pointer hover:bg-gray-50" @click="row._open=!row._open">
                                    <span class="w-8 text-gray-400" x-text="row._open?'▾':'▸'"></span>
                                    <span class="flex-1 font-semibold" x-text="row.label"></span>
                                    <span class="w-20 text-right" x-text="row.count"></span>
                                    <span class="w-24 text-right" x-text="row.hits"></span>
                                    <span class="w-24 text-right" x-text="row.misses"></span>
                                    <span class="w-20 text-right" x-text="row.purges"></span>
                                </div>
                                <div x-show="row._open" class="bg-gray-50 border-b border-gray-200">
                                    <div class="flex gap-2 px-6 py-2 border-b border-gray-100">
                                        <input type="text" :x-ref="'gf_uri_'+idx" x-model="row._fUri" placeholder="URI..." class="border border-gray-300 rounded px-2 py-0.5 text-xs w-32">
                                        <input type="text" x-model="row._fIp" placeholder="IP..." class="border border-gray-300 rounded px-2 py-0.5 text-xs w-24">
                                        <select x-model="row._fType" class="border border-gray-300 rounded px-2 py-0.5 text-xs">
                                            <option value="">Visi tipai</option>
                                            <option value="purge">Valymas</option>
                                            <option value="stats">Statistika</option>
                                            <option value="cloudflare">Cloudflare</option>
                                            <option value="error">Klaidos</option>
                                        </select>
                                    </div>
                                    <template x-for="(c, ci) in filteredChildren(row)" :key="ci">
                                        <div class="grid grid-cols-6 gap-2 px-6 py-1 text-xs border-b border-gray-50">
                                            <span x-text="c.timestamp"></span>
                                            <span><span class="px-1.5 py-0.5 rounded text-xs" :style="'background:'+typeColor(c.type)" x-text="c.type"></span></span>
                                            <span class="text-right" x-text="c.hits||'—'"></span>
                                            <span class="text-right" x-text="c.misses||'—'"></span>
                                            <span class="truncate" x-text="c.uri||'—'"></span>
                                            <span x-text="c.ip||'—'"></span>
                                        </div>
                                    </template>
                                    <div x-show="row.children && row.children.length >= 50" class="px-6 py-1 text-xs text-gray-400">Rodoma iki 50 įrašų grupėje</div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
        </template>

        <!-- Flat view with virtual scroll -->
        <template x-if="groupFields.length === 0 && !loading">
            <div>
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="grid grid-cols-12 gap-1 bg-gray-50 px-3 py-2 text-xs font-semibold border-b border-gray-200">
                    <div class="col-span-2 cursor-pointer" @click="toggleSort('timestamp')">Laikas <span x-text="sortIcon('timestamp')"></span></div>
                    <div class="col-span-1 cursor-pointer" @click="toggleSort('type')">Tipas <span x-text="sortIcon('type')"></span></div>
                    <div class="col-span-1 text-right cursor-pointer" @click="toggleSort('hits')">Hit <span x-text="sortIcon('hits')"></span></div>
                    <div class="col-span-1 text-right cursor-pointer" @click="toggleSort('misses')">Miss <span x-text="sortIcon('misses')"></span></div>
                    <div class="col-span-2 cursor-pointer" @click="toggleSort('uri')">URI <span x-text="sortIcon('uri')"></span></div>
                    <div class="col-span-1 cursor-pointer" @click="toggleSort('ip')">IP <span x-text="sortIcon('ip')"></span></div>
                    <div class="col-span-2 cursor-pointer" @click="toggleSort('user_name')">Vartotojas <span x-text="sortIcon('user_name')"></span></div>
                    <div class="col-span-2">Detalės</div>
                </div>
                <div class="overflow-y-auto" style="height:600px" x-ref="scroller" @scroll="onScroll()">
                    <div :style="'height:'+totalHeight+'px;position:relative'">
                        <template x-for="(row, i) in visibleRows" :key="row._idx">
                            <div class="grid grid-cols-12 gap-1 px-3 items-center border-b border-gray-50 text-xs absolute w-full" :style="'top:'+row._top+'px;height:40px;line-height:40px'">
                                <div class="col-span-2 truncate" x-text="row.timestamp"></div>
                                <div class="col-span-1"><span class="px-1.5 py-0.5 rounded" :style="'background:'+typeColor(row.type)" x-text="row.type"></span></div>
                                <div class="col-span-1 text-right" x-text="row.hits||'—'"></div>
                                <div class="col-span-1 text-right" x-text="row.misses||'—'"></div>
                                <div class="col-span-2 truncate"><code class="text-xs" x-text="(row.uri||'').substring(0,60)"></code></div>
                                <div class="col-span-1 truncate" x-text="row.ip"></div>
                                <div class="col-span-2 truncate leading-tight" style="line-height:1.3;padding:4px 0" x-html="userHtml(row)"></div>
                                <div class="col-span-2 truncate text-gray-500" x-text="(row.details_str||'').substring(0,80)"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div class="mt-2 text-sm text-gray-500" x-text="sortedRows.length+' įrašų'"></div>
            </div>
        </template>
        </div>

        <script>
        function vltLogs() {
            const ROW_H = 40, BUFFER = 20;
            return {
                filters: { date: '<?php echo esc_js($date); ?>', type: '', ip: '', uri: '', user: '' },
                groupFields: [],
                groupOptions: [
                    {key:'minute',label:'Minutę'},{key:'hour',label:'Valandą'},{key:'day',label:'Dieną'},
                    {key:'week',label:'Savaitę'},{key:'month',label:'Mėnesį'},{key:'year',label:'Metus'},
                    {key:'uri',label:'URL'},{key:'ip',label:'IP'},{key:'type',label:'Tipą'},{key:'user_id',label:'Vartotoją'}
                ],
                rows: [], meta: {total:0,totalHits:0,totalMisses:0,ratio:0},
                loading: false, sortCol: 'timestamp', sortDir: 'desc',
                suggestions: [], showSuggest: false, allUris: [],
                scrollTop: 0, lastTs: null, evtSource: null,

                init() {
                    this.fetchUris();
                    this.fullFetch();
                    this.startSSE();
                },

                startSSE() {
                    if (this.evtSource) this.evtSource.close();
                    this.evtSource = new EventSource('<?php echo $sse_url; ?>');
                    this.evtSource.onmessage = (e) => {
                        try {
                            const items = JSON.parse(e.data);
                            if (!items || !items.length || this.groupFields.length) return;
                            const newRows = items.map(r => ({...r, _open:false, _fUri:'', _fIp:'', _fType:''}));
                            this.rows = [...newRows, ...this.rows];
                            if (this.rows.length) {
                                this.lastTs = this.rows.reduce((m,r) => (r.timestamp||'') > m ? r.timestamp : m, this.lastTs||'');
                            }
                            this.meta.total = this.rows.length;
                        } catch(err) {}
                    };
                },

                async fullFetch() {
                    this.loading = true;
                    this.lastTs = null;
                    const p = new URLSearchParams({
                        
                        date: this.filters.date, type: this.filters.type, ip: this.filters.ip,
                        uri: this.filters.uri, user: this.filters.user,
                        group: this.groupFields.join(',')
                    });
                    try {
                        const r = await fetch('<?php echo $rest_url; ?>/logs?' + p, {headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});
                        const d = await r.json();
                        if (d.rows) {
                            this.rows = d.rows.map(r => ({...r, _open: false, _fUri:'', _fIp:'', _fType:''}));
                            this.meta = d.meta;
                            if (this.rows.length && !this.groupFields.length) {
                                this.lastTs = this.rows.reduce((m,r) => r.timestamp > m ? r.timestamp : m, '');
                            }
                        }
                    } catch(e) { console.error(e); }
                    this.loading = false;
                },

                async fetchUris() {
                    try {
                        const r = await fetch('<?php echo $rest_url; ?>/logs/uris?date=' + this.filters.date, {headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});
                        const d = await r.json();
                        if (Array.isArray(d)) this.allUris = d;
                    } catch(e) {}
                },

                onUriInput() {
                    const v = this.filters.uri.toLowerCase();
                    this.suggestions = v ? this.allUris.filter(u => u.toLowerCase().includes(v)).slice(0, 15) : [];
                    this.showSuggest = true;
                    this.fullFetch();
                },

                toggleSort(col) {
                    if (this.sortCol === col) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                    else { this.sortCol = col; this.sortDir = 'desc'; }
                },
                sortIcon(col) { return this.sortCol === col ? (this.sortDir === 'asc' ? '▲' : '▼') : ''; },

                get sortedRows() {
                    const col = this.sortCol, dir = this.sortDir;
                    return [...this.rows].sort((a, b) => {
                        let va = a[col] ?? '', vb = b[col] ?? '';
                        if (typeof va === 'number' && typeof vb === 'number') return dir === 'asc' ? va - vb : vb - va;
                        return dir === 'asc' ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
                    });
                },

                get totalHeight() { return this.sortedRows.length * ROW_H; },
                onScroll() { this.scrollTop = this.$refs.scroller?.scrollTop || 0; },

                get visibleRows() {
                    const sorted = this.sortedRows;
                    const start = Math.max(0, Math.floor(this.scrollTop / ROW_H) - BUFFER);
                    const visible = Math.ceil(600 / ROW_H) + BUFFER * 2;
                    const end = Math.min(sorted.length, start + visible);
                    const out = [];
                    for (let i = start; i < end; i++) {
                        out.push({...sorted[i], _idx: i, _top: i * ROW_H});
                    }
                    return out;
                },

                filteredChildren(row) {
                    if (!row.children) return [];
                    return row.children.filter(c => {
                        if (row._fUri && !(c.uri||'').toLowerCase().includes(row._fUri.toLowerCase())) return false;
                        if (row._fIp && !(c.ip||'').includes(row._fIp)) return false;
                        if (row._fType && c.type !== row._fType) return false;
                        return true;
                    });
                },

                typeColor(t) {
                    return {purge:'#fff3cd',error:'#f8d7da',cloudflare:'#d1ecf1',stats:'#d4edda'}[t] || '#e2e3e5';
                },

                userHtml(row) {
                    if (row.user_id && row.user_id > 0 && row.profile_url) {
                        return '<a href="'+this.esc(row.profile_url)+'" class="text-blue-600 hover:underline">'+this.esc(row.user_name)+'</a><br><span class="text-gray-400 text-xs">'+this.esc(row.user_email||'')+'</span>';
                    }
                    return this.esc(row.user_name || '—');
                },
                esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
            };
        }
        </script>
        <?php
    }
}
