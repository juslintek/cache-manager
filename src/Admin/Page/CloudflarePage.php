<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;

final class CloudflarePage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-cf'; }
    public function title(): string { return 'Cloudflare'; }

    public function render(): void
    {
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        $sse_url  = esc_js(rest_url('vlt-cache/v1/cloudflare/stream') . '?_wpnonce=' . $nonce);
        $date     = gmdate('Y-m-d');
        ?>
        <div class="wrap tw-text-sm" x-data="vltCf()" x-init="init()" x-cloak>
        <h1 class="tw-text-2xl tw-font-bold tw-mb-4">Podėlio Valdymas — Cloudflare</h1>

        <div class="tw-flex tw-flex-wrap tw-gap-3 tw-items-end tw-mb-4">
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">Data</label>
                <input type="date" x-model="date" @change="loadDate()" class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm">
            </div>
            <div>
                <label class="tw-block tw-text-xs tw-font-semibold tw-mb-1">Paieška</label>
                <input type="text" x-model.debounce.300ms="search" placeholder="URI, IP, naršyklė..." class="tw-border tw-border-gray-300 tw-rounded tw-px-2 tw-py-1 tw-text-sm tw-w-48">
            </div>
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-xs">
                <span class="tw-flex tw-items-center tw-gap-1" :class="sseConnected?'text-green-600':'text-red-500'">
                    <span class="w-2 tw-h-2 tw-rounded-full" :class="sseConnected?'bg-green-500 animate-pulse':'bg-red-400'"></span>
                    <span x-text="sseConnected?'Gyvai':'Atsijungta'"></span>
                </span>
                <span class="tw-text-gray-400" x-text="rows.length+' įrašų'"></span>
            </div>
        </div>

        <div x-show="loading" class="py-8 tw-text-center tw-text-gray-500">Kraunama...</div>

        <template x-if="!loading">
        <div>
            <!-- Logged users -->
            <div class="tw-mb-4">
                <h2 class="tw-text-base tw-font-semibold tw-mb-2 tw-cursor-pointer tw-flex tw-items-center tw-gap-2" @click="showLogged=!showLogged">
                    <span x-text="showLogged?'▾':'▸'"></span> Prisijungę vartotojai (<span x-text="loggedRows.length"></span>)
                </h2>
                <div x-show="showLogged" class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-overflow-auto tw-max-h-[45vh]">
                <table class="tw-w-full tw-text-xs">
                    <thead class="tw-bg-gray-50 tw-sticky top-0"><tr>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-36">Laikas</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-40">Vartotojas</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-24">IP</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-10">🌍</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left">URI</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-36">CF-RAY</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-12">Iššūkis</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-40">Naršyklė</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in loggedRows" :key="r.ray||i">
                            <tr class="tw-border-b border-gray-50 hover:tw-bg-gray-50" :class="r.challenged?'bg-yellow-50':''">
                                <td class="tw-px-2 tw-py-1" x-text="r.ts?.substring(11,19)||r.ts"></td>
                                <td class="tw-px-2 tw-py-1 tw-leading-tight">
                                    <span class="text-blue-600" x-text="r.user_name"></span>
                                    <br><span class="tw-text-gray-400 tw-text-[10px]" x-text="r.user_email"></span>
                                </td>
                                <td class="tw-px-2 tw-py-1" x-text="r.ip"></td>
                                <td class="tw-px-2 tw-py-1" :title="countryName(r.country)"><span x-text="flag(r.country)"></span></td>
                                <td class="tw-px-2 tw-py-1 tw-truncate tw-max-w-[200px]" :title="r.uri" x-text="r.uri"></td>
                                <td class="tw-px-2 tw-py-1 tw-font-mono tw-text-[10px]" x-text="r.ray"></td>
                                <td class="tw-px-2 tw-py-1" x-text="r.challenged?'⚠️':''" :title="r.challenged?'Iššūkis':'Ne'"></td>
                                <td class="tw-px-2 tw-py-1 tw-text-[10px] tw-text-gray-400 tw-truncate tw-max-w-[180px]" :title="r.ua||''" x-text="shortUa(r.ua)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Anonymous -->
            <div>
                <h2 class="tw-text-base tw-font-semibold tw-mb-2 tw-cursor-pointer tw-flex tw-items-center tw-gap-2" @click="showAnon=!showAnon">
                    <span x-text="showAnon?'▾':'▸'"></span> Anoniminiai (<span x-text="anonRows.length"></span>)
                </h2>
                <div x-show="showAnon" class="tw-bg-white tw-border tw-border-gray-200 tw-rounded-lg tw-overflow-auto tw-max-h-[45vh]">
                <table class="tw-w-full tw-text-xs">
                    <thead class="tw-bg-gray-50 tw-sticky top-0"><tr>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-36">Laikas</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-24">IP</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-10">🌍</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left">URI</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-36">CF-RAY</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left tw-w-12">Iššūkis</th>
                        <th class="tw-px-2 tw-py-1.5 tw-text-left w-40">Naršyklė</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in anonRows" :key="r.ray||i">
                            <tr class="tw-border-b border-gray-50 hover:tw-bg-gray-50" :class="r.challenged?'bg-yellow-50':''">
                                <td class="tw-px-2 tw-py-1" x-text="r.ts?.substring(11,19)||r.ts"></td>
                                <td class="tw-px-2 tw-py-1" x-text="r.ip"></td>
                                <td class="tw-px-2 tw-py-1" :title="countryName(r.country)"><span x-text="flag(r.country)"></span></td>
                                <td class="tw-px-2 tw-py-1 tw-truncate tw-max-w-[250px]" :title="r.uri" x-text="r.uri"></td>
                                <td class="tw-px-2 tw-py-1 tw-font-mono tw-text-[10px]" x-text="r.ray"></td>
                                <td class="tw-px-2 tw-py-1" x-text="r.challenged?'⚠️':''" :title="r.challenged?'Iššūkis':'Ne'"></td>
                                <td class="tw-px-2 tw-py-1 tw-text-[10px] tw-text-gray-400 tw-truncate tw-max-w-[180px]" :title="r.ua||''" x-text="shortUa(r.ua)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        </template>
        </div>

        <script>
        function vltCf() {
            return {
                date: '<?php echo esc_js($date); ?>', search: '',
                rows: [], loading: false, evtSource: null, sseConnected: false,
                showLogged: true, showAnon: true, raySet: new Set(),

                init() {
                    this.loadDate();
                    this.startSSE();
                },

                // Single fetch for the selected date
                async loadDate() {
                    this.loading = true;
                    try {
                        const r = await fetch('<?php echo $rest_url; ?>/cloudflare?date='+this.date, {headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});
                        const d = await r.json();
                        if (Array.isArray(d)) {
                            this.rows = d;
                            this.raySet = new Set(d.map(r => r.ray));
                        }
                    } catch(e) {}
                    this.loading = false;
                },

                // SSE for live updates — backend pushes new requests
                startSSE() {
                    if (this.evtSource) this.evtSource.close();
                    this.evtSource = new EventSource('<?php echo $sse_url; ?>');
                    this.evtSource.onopen = () => { this.sseConnected = true; };
                    this.evtSource.onmessage = (e) => {
                        try {
                            const items = JSON.parse(e.data);
                            if (!items || !items.length) return;
                            // Deduplicate by ray ID
                            const fresh = items.filter(r => r.ray && !this.raySet.has(r.ray));
                            if (fresh.length) {
                                fresh.forEach(r => this.raySet.add(r.ray));
                                this.rows = [...fresh, ...this.rows].slice(0, 1000);
                            }
                        } catch(err) {}
                    };
                    this.evtSource.onerror = () => { this.sseConnected = false; };
                },

                get filtered() {
                    if (!this.search) return this.rows;
                    const s = this.search.toLowerCase();
                    return this.rows.filter(r =>
                        (r.uri||'').toLowerCase().includes(s) ||
                        (r.ip||'').includes(s) ||
                        (r.user_name||'').toLowerCase().includes(s) ||
                        (r.ua||'').toLowerCase().includes(s) ||
                        (r.country||'').toLowerCase().includes(s)
                    );
                },
                get loggedRows() { return this.filtered.filter(r => r.user_id > 0); },
                get anonRows() { return this.filtered.filter(r => !r.user_id || r.user_id <= 0); },

                // Country code → flag emoji
                flag(code) {
                    if (!code || code.length !== 2) return '';
                    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
                },

                // Country code → full name
                countryName(code) {
                    if (!code) return '';
                    try { return new Intl.DisplayNames(['lt'], {type:'region'}).of(code.toUpperCase()); } catch(e) { return code; }
                },

                // Shorten user agent
                shortUa(ua) {
                    if (!ua) return '—';
                    const m = ua.match(/(Chrome|Firefox|Safari|Edge|Opera|Bot|curl|Googlebot|Bingbot|Yandex|facebookexternalhit|Twitterbot|WhatsApp|Telegram)[\/\s]?([\d.]*)/i);
                    return m ? m[1] + (m[2] ? ' ' + m[2].split('.')[0] : '') : ua.substring(0, 30);
                },

                destroy() { if (this.evtSource) this.evtSource.close(); }
            };
        }
        </script>
        <?php
    }
}
