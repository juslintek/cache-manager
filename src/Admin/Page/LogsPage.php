<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class LogsPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-logs'; }
    public function title(): string { return 'Logs'; }

    public function render(): void
    {
        $date     = sanitize_text_field($_GET['log_date'] ?? gmdate('Y-m-d'));
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        $sse_url  = esc_js(rest_url('vlt-cache/v1/logs/stream') . '?_wpnonce=' . $nonce);
        ?>
        <div class="wrap" x-data="vltLogs()" x-init="init()" x-cloak>
        <h1 class="tw-text-2xl tw-font-bold tw-mb-4">Podėlio Valdymas — Žurnalai</h1>

        <!-- Filters -->
        <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-p-4 tw-mb-4 tw-grid grid-cols-2 md:grid-cols-6 tw-gap-3">
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">Data</label>
                <input type="date" x-model="filters.date" @change="fullFetch()" class="tw-w-full tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm">
            </div>
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">Tipas</label>
                <select x-model="filters.type" @change="fullFetch()" class="tw-w-full tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm">
                    <option value="">Visi</option>
                    <option value="purge">Valymas</option>
                    <option value="stats">Statistika</option>
                    <option value="cloudflare">Cloudflare</option>
                    <option value="error">Klaidos</option>
                </select>
            </div>
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">IP</label>
                <input type="text" x-model.debounce.300ms="filters.ip" @input="fullFetch()" placeholder="pvz. 84.15" class="tw-w-full tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm">
            </div>
            <div class="tw-relative">
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">URL</label>
                <input type="text" x-model="filters.uri" @input="onUriInput()" @focus="showSuggest=true" @blur="setTimeout(()=>showSuggest=false,200)" placeholder="pvz. /naujienos" class="tw-w-full tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm" autocomplete="off">
                <div x-show="showSuggest && suggestions.length" class="tw-absolute tw-z-50 tw-bg-white tw-border tw-border-gray-200 tw-shadow-lg tw-rounded tw-mt-1 max-h-48 tw-overflow-auto tw-w-full">
                    <template x-for="s in suggestions" :key="s">
                        <div @mousedown.prevent="filters.uri=s;showSuggest=false;fullFetch()" class="tw-px-2 tw-py-1 tw-text-xs tw-cursor-pointer hover:tw-bg-blue-50" x-text="s"></div>
                    </template>
                </div>
            </div>
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">Vartotojas</label>
                <input type="text" x-model.debounce.300ms="filters.user" @input="fullFetch()" placeholder="vardas / ID" class="tw-w-full tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm">
            </div>
        </div>

        <!-- Group by -->
        <div class="tw-flex tw-flex-wrap tw-gap-3 tw-items-center tw-mb-3 tw-px-3 tw-py-2 tw-bg-gray-50 tw-border tw-border-gray-200 tw-rounded-lg tw-text-sm">
            <span class="tw-font-semibold">Grupuoti pagal:</span>
            <template x-for="g in groupOptions" :key="g.key">
                <label class="tw-flex tw-items-center tw-gap-1 tw-cursor-pointer">
                    <input type="checkbox" :value="g.key" x-model="groupFields" @change="fullFetch()">
                    <span x-text="g.label"></span>
                </label>
            </template>
        </div>

        <!-- Stats bar -->
        <div class="tw-flex tw-gap-4 tw-mb-3 tw-text-sm" x-show="meta.total>0">
            <span><strong x-text="meta.total"></strong> įrašų</span>
            <span>Pataikymai: <strong x-text="meta.totalHits"></strong></span>
            <span>Praleidimai: <strong x-text="meta.totalMisses"></strong></span>
            <span>Santykis: <strong x-text="meta.ratio+'%'"></strong></span>
        </div>

        <div x-show="loading" class="py-8 tw-text-center tw-text-gray-500">Kraunama...</div>

        <!-- Grouped view -->
        <template x-if="groupFields.length > 0 && !loading">
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-overflow-hidden">
            <table class="tw-w-full tw-text-sm">
                <thead class="tw-bg-gray-50"><tr>
                    <th class="tw-px-3 tw-py-2 tw-text-left tw-w-8"></th>
                    <th class="tw-px-3 tw-py-2 tw-text-left tw-cursor-pointer" @click="toggleSort('label')">Grupė <span x-text="sortIcon('label')"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-20 tw-cursor-pointer" @click="toggleSort('count')">Įrašų <span x-text="sortIcon('count')"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-24 tw-cursor-pointer" @click="toggleSort('hits')">Pataikymai <span x-text="sortIcon('hits')"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-24 tw-cursor-pointer" @click="toggleSort('misses')">Praleidimai <span x-text="sortIcon('misses')"></span></th>
                    <th class="tw-px-3 tw-py-2 tw-text-right tw-w-20 tw-cursor-pointer" @click="toggleSort('purges')">Valymai <span x-text="sortIcon('purges')"></span></th>
                </tr></thead>
                <tbody>
                    <template x-for="(row, idx) in sortedRows" :key="idx">
                        <tr>
                            <td colspan="6" class="p-0">
                                <div class="tw-flex tw-items-center tw-px-3 tw-py-2 tw-border-b tw-border-gray-100 tw-cursor-pointer hover:tw-bg-gray-50" @click="row._open=!row._open">
                                    <span class="tw-w-8 tw-text-gray-400" x-text="row._open?'▾':'▸'"></span>
                                    <span class="tw-flex-1 tw-font-semibold" x-text="row.label"></span>
                                    <span class="tw-w-20 tw-text-right" x-text="row.count"></span>
                                    <span class="tw-w-24 tw-text-right" x-text="row.hits"></span>
                                    <span class="tw-w-24 tw-text-right" x-text="row.misses"></span>
                                    <span class="tw-w-20 tw-text-right" x-text="row.purges"></span>
                                </div>
                                <div x-show="row._open" class="tw-bg-gray-50 tw-border-b tw-border-gray-200">
                                    <div class="tw-flex tw-gap-2 tw-px-6 tw-py-2 tw-border-b tw-border-gray-100">
                                        <input type="text" :x-ref="'gf_uri_'+idx" x-model="row._fUri" placeholder="URI..." class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-0.5 tw-text-xs tw-w-32">
                                        <input type="text" x-model="row._fIp" placeholder="IP..." class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-0.5 tw-text-xs tw-w-24">
                                        <select x-model="row._fType" class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-0.5 tw-text-xs">
                                            <option value="">Visi tipai</option>
                                            <option value="purge">Valymas</option>
                                            <option value="stats">Statistika</option>
                                            <option value="cloudflare">Cloudflare</option>
                                            <option value="error">Klaidos</option>
                                        </select>
                                    </div>
                                    <template x-for="(c, ci) in filteredChildren(row)" :key="ci">
                                        <div class="tw-grid grid-cols-6 tw-gap-2 tw-px-6 tw-py-1 tw-text-xs tw-border-b border-gray-50">
                                            <span x-text="c.timestamp"></span>
                                            <span><span class="tw-px-1.5 tw-py-0.5 tw-rounded tw-text-xs" :style="'background:'+typeColor(c.type)" x-text="c.type"></span></span>
                                            <span class="tw-text-right" x-text="c.hits||'—'"></span>
                                            <span class="tw-text-right" x-text="c.misses||'—'"></span>
                                            <span class="tw-truncate" x-text="c.uri||'—'"></span>
                                            <span x-text="c.ip||'—'"></span>
                                        </div>
                                    </template>
                                    <div x-show="row.children && row.children.length >= 50" class="tw-px-6 tw-py-1 tw-text-xs tw-text-gray-400">Rodoma iki 50 įrašų grupėje</div>
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
            <div class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-overflow-hidden">
                <div class="tw-grid grid-cols-12 tw-gap-1 tw-bg-gray-50 tw-px-3 tw-py-2 tw-text-xs tw-font-semibold tw-border-b tw-border-gray-200">
                    <div class="col-span-2 tw-cursor-pointer" @click="toggleSort('timestamp')">Laikas <span x-text="sortIcon('timestamp')"></span></div>
                    <div class="col-span-1 tw-cursor-pointer" @click="toggleSort('type')">Tipas <span x-text="sortIcon('type')"></span></div>
                    <div class="col-span-1 tw-text-right tw-cursor-pointer" @click="toggleSort('hits')">Hit <span x-text="sortIcon('hits')"></span></div>
                    <div class="col-span-1 tw-text-right tw-cursor-pointer" @click="toggleSort('misses')">Miss <span x-text="sortIcon('misses')"></span></div>
                    <div class="col-span-2 tw-cursor-pointer" @click="toggleSort('uri')">URI <span x-text="sortIcon('uri')"></span></div>
                    <div class="col-span-1 tw-cursor-pointer" @click="toggleSort('ip')">IP <span x-text="sortIcon('ip')"></span></div>
                    <div class="col-span-2 tw-cursor-pointer" @click="toggleSort('user_name')">Vartotojas <span x-text="sortIcon('user_name')"></span></div>
                    <div class="col-span-2">Detalės</div>
                </div>
                <div class="tw-overflow-y-auto" style="height:600px" x-ref="scroller" @scroll="onScroll()">
                    <div :style="'height:'+totalHeight+'px;position:relative'">
                        <template x-for="(row, i) in visibleRows" :key="row._idx">
                            <div class="tw-grid grid-cols-12 tw-gap-1 tw-px-3 tw-items-center tw-border-b border-gray-50 tw-text-xs tw-absolute tw-w-full" :style="'top:'+row._top+'px;height:40px;line-height:40px'">
                                <div class="col-span-2 tw-truncate" x-text="row.timestamp"></div>
                                <div class="col-span-1"><span class="tw-px-1.5 tw-py-0.5 tw-rounded" :style="'background:'+typeColor(row.type)" x-text="row.type"></span></div>
                                <div class="col-span-1 tw-text-right" x-text="row.hits||'—'"></div>
                                <div class="col-span-1 tw-text-right" x-text="row.misses||'—'"></div>
                                <div class="col-span-2 tw-truncate"><code class="tw-text-xs" x-text="(row.uri||'').substring(0,60)"></code></div>
                                <div class="col-span-1 tw-truncate" x-text="row.ip"></div>
                                <div class="col-span-2 tw-truncate tw-leading-tight" style="line-height:1.3;padding:4px 0" x-html="userHtml(row)"></div>
                                <div class="col-span-2 tw-truncate tw-text-gray-500" x-text="(row.details_str||'').substring(0,80)"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div class="tw-mt-2 tw-text-sm tw-text-gray-500" x-text="sortedRows.length+' įrašų'"></div>
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
                        return '<a href="'+this.esc(row.profile_url)+'" class="text-blue-600 hover:underline">'+this.esc(row.user_name)+'</a><br><span class="tw-text-gray-400 tw-text-xs">'+this.esc(row.user_email||'')+'</span>';
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
