<?php

declare(strict_types=1);

namespace VLT\CacheManager\Admin\Page;

use VLT\CacheManager\Admin\AdminPage;
use VLT\CacheManager\Tracer\TracerConfig;

final class TracerPage extends AdminPage
{
    public function slug(): string { return 'vlt-cache-tracer'; }
    public function title(): string { return 'Tracer'; }

    public function render(): void
    {
        $rest_url = esc_js(rest_url('vlt-cache/v1'));
        $nonce    = wp_create_nonce('wp_rest');
        $max      = (int) get_option('vlt_trace_max', TracerConfig::VLT_TR_MAX);
        $sse_url  = esc_js(rest_url('vlt-cache/v1/tracer/stream') . '?_wpnonce=' . $nonce);

        // ── Extension status notices ──────────────────────────────────────────
        $hasExcimer = class_exists('ExcimerProfiler');

        if (!$hasExcimer) {
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>Tracer:</strong> <code>excimer</code> PHP plėtinys neįdiegtas. ';
            echo 'Tracer veikia be profiliavimo — tik laiko žymės. ';
            echo 'Įdiekite: <code>pecl install excimer</code>';
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-success inline"><p>✅ <strong>Excimer</strong> įdiegtas — tikslus mėginių profiliavimas aktyvus (~2% apkrova).</p></div>';
        }

        // ── Trace worker status ───────────────────────────────────────────────
        $workerStatus = \VLT\CacheManager\Tracer\TraceWorker::status();
        echo '<div style="margin:8px 0;padding:8px 12px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;display:flex;align-items:center;gap:12px;font-size:12px">';
        echo '<strong>Trace Worker:</strong> ';
        if ($workerStatus['running']) {
            echo '<span style="color:#46b450">● Veikia</span>';
            echo ' &nbsp; Eilėje: <strong>' . $workerStatus['queue_len'] . '</strong>';
            echo ' &nbsp; Heartbeat: ' . esc_html($workerStatus['heartbeat'] ?? '—');
            echo ' &nbsp; <button type="button" id="vlt-worker-stop" class="button button-small">⏹ Sustabdyti</button>';
        } else {
            echo '<span style="color:#d63638">● Sustabdytas</span>';
            echo ' &nbsp; Eilėje: <strong>' . $workerStatus['queue_len'] . '</strong>';
            echo ' &nbsp; <button type="button" id="vlt-worker-start" class="button button-small button-primary">▶ Paleisti</button>';
        }
        echo '<span id="vlt-worker-status" style="color:#666"></span>';
        echo '</div>';
        echo '<script>
        ["vlt-worker-start","vlt-worker-stop"].forEach(id => {
            document.getElementById(id)?.addEventListener("click", function() {
                const action = id.includes("start") ? "start" : "stop";
                const s = document.getElementById("vlt-worker-status");
                this.disabled = true; s.textContent = "...";
                fetch("' . esc_js(rest_url('vlt-cache/v1/trace-worker/')) . '" + action, {
                    method: "POST", headers: {"X-WP-Nonce": "' . $nonce . '"}
                }).then(r=>r.json()).then(()=>{ s.textContent="✅"; setTimeout(()=>location.reload(),1000); })
                .catch(()=>{ s.textContent="❌"; this.disabled=false; });
            });
        });
        </script>';
        ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.14.9/cdn.min.js" defer></script>

        <div class="wrap text-xs" x-data="vltT()" x-init="init()" x-cloak>
        <h1 class="text-2xl font-bold mb-2">VLT Tracer</h1>

        <!-- Controls -->
        <div class="flex flex-wrap items-center gap-2 bg-white border border-gray-200 rounded-lg p-3 mb-3">
            <span class="text-green-600 font-semibold">● Visada aktyvus</span>
            <span class="text-gray-500" x-text="currentRows.length+' pėdsakų'"></span>
            <span class="flex-1"></span>
            <label class="flex items-center gap-1">Max: <input type="number" min="10" max="10000" class="w-16 border border-gray-300 rounded px-1 py-0.5" x-model.number="maxT" @change="rpc('set_max',{max:maxT})"></label>
        </div>

        <!-- Tabs -->
        <div class="flex mb-0">
            <div class="px-4 py-2 bg-gray-100 border border-gray-300 border-b-0 rounded-t cursor-pointer -mr-px" :class="tab==='live'&&'bg-white border-b-white z-10 relative'" @click="tab='live'">🔴 Gyvai</div>
            <div class="px-4 py-2 bg-gray-100 border border-gray-300 border-b-0 rounded-t cursor-pointer -mr-px" :class="tab==='explorer'&&'bg-white border-b-white z-10 relative'" @click="tab='explorer'">🔍 Naršyklė</div>
            <div class="px-4 py-2 bg-gray-100 border border-gray-300 border-b-0 rounded-t cursor-pointer" :class="tab==='detail'&&'bg-white border-b-white z-10 relative'" x-show="detailTrace" @click="tab='detail'">📋 Detalės</div>
        </div>

        <!-- ==================== LIVE PANEL ==================== -->
        <div class="border border-gray-300 rounded-b rounded-tr bg-white p-3" x-show="tab==='live'">
            <div class="flex items-center gap-3 mb-2">
                <span class="flex items-center gap-1 text-xs" :class="sseConnected?'text-green-600':'text-red-500'">
                    <span class="w-2 h-2 rounded-full" :class="sseConnected?'bg-green-500 animate-pulse':'bg-red-400'"></span>
                    <span x-text="sseConnected?'Gyvai':'Atsijungta'"></span>
                </span>
                <span class="text-gray-400 text-xs">Spustelėkite eilutę → detalės</span>
            </div>
            <div class="overflow-auto" style="max-height:calc(100vh - 340px)">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none" @click="ss('ts')">Laikas <span x-text="si('ts')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-10" @click="ss('method')">Met.</th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-9" @click="ss('status')">St.</th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none" @click="ss('uri')">URI</th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-16" @click="ss('total_ms')">ms <span x-text="si('total_ms')"></span></th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-10" @click="ss('db_n')">DB#</th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-14" @click="ss('mem')">Mem</th>
                    <th class="px-2 py-1.5 text-left w-20">Vartotojas</th>
                </tr></thead>
                <tbody>
                <template x-for="t in liveSorted" :key="t.id">
                    <tr class="border-b border-gray-50 hover:bg-gray-50 cursor-pointer" @click="openDetail(t)">
                        <td class="px-2 py-1 text-gray-400" x-text="t.ts.substring(11,19)"></td>
                        <td class="px-2 py-1 font-bold" x-text="t.method"></td>
                        <td class="px-2 py-1"><span class="px-1.5 py-0.5 rounded text-[10px]" :class="t.status>=400?'bg-red-50':'bg-green-50'" x-text="t.status"></span></td>
                        <td class="px-2 py-1 truncate max-w-xs" :title="t.uri" x-text="t.uri"></td>
                        <td class="px-2 py-1 text-right font-mono font-semibold" :class="t.total_ms>1000?'text-red-600':t.total_ms>300?'text-yellow-600':'text-green-600'" x-text="t.total_ms"></td>
                        <td class="px-2 py-1 text-right text-gray-500" x-text="t.db_n"></td>
                        <td class="px-2 py-1 text-right text-gray-500" x-text="sz(t.mem)"></td>
                        <td class="px-2 py-1 text-gray-400 text-[10px]" x-text="t.user_name||'—'"></td>
                    </tr>
                </template>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ==================== EXPLORER PANEL ==================== -->
        <div class="border border-gray-300 rounded-b rounded-tr bg-white p-3" x-show="tab==='explorer'">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <label class="flex items-center gap-1">Data: <input type="date" class="border border-gray-300 rounded px-1 py-0.5" x-model="expDate" @change="loadExp()"></label>
                <button class="button" @click="loadExp()">Įkelti</button>
                <span class="text-gray-500" x-text="expFiltered.length+'/'+expData.length+' įrašų'"></span>
            </div>
            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-1.5 bg-gray-50 border border-gray-200 rounded p-2 mb-2">
                <label class="flex items-center gap-1 text-gray-600">Met: <select class="border border-gray-300 rounded px-1 py-0.5" x-model="ef.method"><option value="">—</option><option>GET</option><option>POST</option><option>PUT</option><option>DELETE</option></select></label>
                <label class="flex items-center gap-1 text-gray-600">URI: <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-28" x-model="ef.uri" placeholder="/..."></label>
                <label class="flex items-center gap-1 text-gray-600">St: <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-10" x-model="ef.status" placeholder="200"></label>
                <label class="flex items-center gap-1 text-gray-600">IP: <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-20" x-model="ef.ip"></label>
                <label class="flex items-center gap-1 text-gray-600">ms≥ <input type="number" class="border border-gray-300 rounded px-1 py-0.5 w-12" x-model.number="ef.minMs"></label>
                <label class="flex items-center gap-1 text-gray-600">ms≤ <input type="number" class="border border-gray-300 rounded px-1 py-0.5 w-12" x-model.number="ef.maxMs"></label>
                <label class="flex items-center gap-1 text-gray-600">DB#≥ <input type="number" class="border border-gray-300 rounded px-1 py-0.5 w-10" x-model.number="ef.minDb"></label>
                <label class="flex items-center gap-1 text-gray-600">DBms≥ <input type="number" class="border border-gray-300 rounded px-1 py-0.5 w-12" x-model.number="ef.minDbMs"></label>
                <label class="flex items-center gap-1 text-gray-600">Mem≥MB: <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-10" x-model="ef.minMem"></label>
                <label class="flex items-center gap-1 text-gray-600">Vart: <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-14" x-model="ef.user" placeholder="vardas"></label>
                <label class="flex items-center gap-1 text-gray-600">🌍 <input type="text" class="border border-gray-300 rounded px-1 py-0.5 w-8" x-model="ef.co" placeholder="LT"></label>
                <button class="px-2 py-0.5 bg-gray-200 hover:bg-gray-300 rounded cursor-pointer" @click="ef={method:'',uri:'',status:'',ip:'',minMs:0,maxMs:0,minDb:0,minDbMs:0,minMem:'',user:'',co:''}">✕</button>
            </div>
            <div class="overflow-auto" style="max-height:calc(100vh - 420px)">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none" @click="es('ts')">Laikas <span x-text="esi('ts')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-10" @click="es('method')">Met. <span x-text="esi('method')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-9" @click="es('status')">St. <span x-text="esi('status')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none" @click="es('uri')">URI <span x-text="esi('uri')"></span></th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-16" @click="es('total_ms')">ms <span x-text="esi('total_ms')"></span></th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-14" @click="es('db_ms')">DB ms <span x-text="esi('db_ms')"></span></th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-10" @click="es('db_n')">DB# <span x-text="esi('db_n')"></span></th>
                    <th class="px-2 py-1.5 text-right cursor-pointer select-none w-14" @click="es('mem')">Mem <span x-text="esi('mem')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-24" @click="es('ip')">IP <span x-text="esi('ip')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-20" @click="es('user_name')">Vart. <span x-text="esi('user_name')"></span></th>
                    <th class="px-2 py-1.5 text-left cursor-pointer select-none w-8" @click="es('cf_co')">🌍 <span x-text="esi('cf_co')"></span></th>
                </tr></thead>
                <tbody>
                <template x-for="t in expSorted" :key="t.id">
                    <tr class="border-b border-gray-50 hover:bg-gray-50 cursor-pointer" @click="openDetail(t)">
                        <td class="px-2 py-1 text-gray-400" x-text="t.ts.substring(11,19)"></td>
                        <td class="px-2 py-1 font-bold" x-text="t.method"></td>
                        <td class="px-2 py-1"><span class="px-1.5 py-0.5 rounded text-[10px]" :class="t.status>=400?'bg-red-50':'bg-green-50'" x-text="t.status"></span></td>
                        <td class="px-2 py-1 truncate max-w-[250px]" :title="t.uri" x-text="t.uri"></td>
                        <td class="px-2 py-1 text-right font-mono font-semibold" :class="t.total_ms>1000?'text-red-600':t.total_ms>300?'text-yellow-600':'text-green-600'" x-text="t.total_ms"></td>
                        <td class="px-2 py-1 text-right font-mono text-gray-500" x-text="t.db_ms"></td>
                        <td class="px-2 py-1 text-right text-gray-500" x-text="t.db_n"></td>
                        <td class="px-2 py-1 text-right text-gray-500" x-text="sz(t.mem)"></td>
                        <td class="px-2 py-1 text-gray-400 text-[10px]" x-text="t.ip"></td>
                        <td class="px-2 py-1 text-gray-400 text-[10px]" x-text="t.user_name||'—'"></td>
                        <td class="px-2 py-1 text-[10px]" x-text="t.cf_co||''"></td>
                    </tr>
                </template>
                </tbody>
            </table>
            </div>
        </div>

        <!-- ==================== DETAIL PANEL ==================== -->
        <div class="border border-gray-300 rounded-b rounded-tr bg-white p-4" x-show="tab==='detail'&&detailTrace">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-sm font-bold" x-text="(detailTrace?.method||'')+' '+(detailTrace?.uri||'')"></h3>
                <button class="button" @click="detailTrace=null;tab='explorer'">← Atgal</button>
            </div>

            <template x-if="detailTrace">
            <div class="space-y-4">
                <!-- Overview -->
                <div>
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">Apžvalga</h4>
                    <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs">
                        <dt class="text-gray-500 font-medium">Trace ID</dt><dd class="break-all min-w-0" x-text="detailTrace.id"></dd>
                        <dt class="text-gray-500 font-medium">Laikas</dt><dd class="break-all min-w-0" x-text="detailTrace.ts"></dd>
                        <dt class="text-gray-500 font-medium">Metodas</dt><dd class="break-all min-w-0" x-text="detailTrace.method"></dd>
                        <dt class="text-gray-500 font-medium">URI</dt><dd class="break-all" x-text="detailTrace.uri"></dd>
                        <dt class="text-gray-500 font-medium">Statusas</dt><dd class="break-all min-w-0" x-text="detailTrace.status"></dd>
                        <dt class="text-gray-500 font-medium">Trukmė</dt><dd class="font-mono font-semibold" :class="detailTrace.total_ms>1000?'text-red-600':detailTrace.total_ms>300?'text-yellow-600':'text-green-600'" x-text="detailTrace.total_ms+'ms'"></dd>
                        <dt class="text-gray-500 font-medium">DB užklausos</dt><dd class="break-all min-w-0" x-text="detailTrace.db_n+' ('+detailTrace.db_ms+'ms)'"></dd>
                        <dt class="text-gray-500 font-medium">Atmintis</dt><dd x-text="sz(detailTrace.mem)"></dd>
                        <dt class="text-gray-500 font-medium">IP</dt><dd class="break-all min-w-0" x-text="detailTrace.ip"></dd>
                        <dt class="text-gray-500 font-medium">PHP</dt><dd class="break-all min-w-0" x-text="detailTrace.php"></dd>
                        <dt class="text-gray-500 font-medium">Tema</dt><dd class="break-all min-w-0" x-text="detailTrace.tpl"></dd>
                        <dt class="text-gray-500 font-medium">Įskiepiai</dt><dd class="break-all min-w-0" x-text="detailTrace.plugins"></dd>
                        <dt class="text-gray-500 font-medium">CF-RAY</dt><dd class="break-all min-w-0" x-text="detailTrace.cf_ray||'—'"></dd>
                        <dt class="text-gray-500 font-medium">Šalis</dt><dd class="break-all min-w-0" x-text="detailTrace.cf_co||'—'"></dd>
                    </dl>
                </div>

                <!-- User -->
                <div x-show="detailTrace.user">
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">Vartotojas</h4>
                    <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs">
                        <dt class="text-gray-500 font-medium">ID</dt><dd class="break-all min-w-0" x-text="detailTrace.user"></dd>
                        <dt class="text-gray-500 font-medium">Vardas</dt><dd class="break-all min-w-0" x-text="detailTrace.user_name||'—'"></dd>
                        <dt class="text-gray-500 font-medium">El. paštas</dt><dd class="break-all min-w-0" x-text="detailTrace.user_email||'—'"></dd>
                        <dt class="text-gray-500 font-medium">Rolės</dt><dd x-text="(detailTrace.user_roles||[]).join(', ')||'—'"></dd>
                    </dl>
                </div>

                <!-- Request -->
                <div>
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">Užklausa (Request)</h4>
                    <template x-if="detailTrace.req_get&&Object.keys(detailTrace.req_get).length">
                        <div class="mb-2"><span class="text-xs font-semibold">GET parametrai:</span>
                        <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs mt-1"><template x-for="k in Object.keys(detailTrace.req_get)" :key="k"><dt class="text-gray-500" x-text="k"></dt><dd><code class="text-[10px] bg-gray-100 px-1 rounded" x-text="detailTrace.req_get[k]"></code></dd></template></dl></div>
                    </template>
                    <template x-if="detailTrace.req_post&&Object.keys(detailTrace.req_post).length">
                        <div class="mb-2"><span class="text-xs font-semibold">POST parametrai:</span>
                        <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs mt-1"><template x-for="k in Object.keys(detailTrace.req_post)" :key="k"><dt class="text-gray-500" x-text="k"></dt><dd><code class="text-[10px] bg-gray-100 px-1 rounded break-all" x-text="typeof detailTrace.req_post[k]==='object'?JSON.stringify(detailTrace.req_post[k]):detailTrace.req_post[k]"></code></dd></template></dl></div>
                    </template>
                    <template x-if="detailTrace.req_body">
                        <div class="mb-2"><span class="text-xs font-semibold">Body:</span>
                        <pre class="bg-gray-100 p-2 rounded text-[10px] max-h-48 overflow-auto whitespace-pre-wrap break-all mt-1" x-text="detailTrace.req_body"></pre></div>
                    </template>
                    <template x-if="detailTrace.req_headers&&Object.keys(detailTrace.req_headers).length">
                        <div class="mb-2"><span class="text-xs font-semibold">Antraštės:</span>
                        <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[10px] mt-1"><template x-for="k in Object.keys(detailTrace.req_headers)" :key="k"><dt class="text-gray-500" x-text="k"></dt><dd class="break-all" x-text="detailTrace.req_headers[k]"></dd></template></dl></div>
                    </template>
                    <template x-if="detailTrace.req_cookies&&Object.keys(detailTrace.req_cookies).length">
                        <div><span class="text-xs font-semibold">Slapukai:</span>
                        <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[10px] mt-1"><template x-for="k in Object.keys(detailTrace.req_cookies)" :key="k"><dt class="text-gray-500" x-text="k"></dt><dd class="break-all" x-text="detailTrace.req_cookies[k]"></dd></template></dl></div>
                    </template>
                </div>

                <!-- Response -->
                <div x-show="detailTrace.resp_headers&&Object.keys(detailTrace.resp_headers).length">
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">Atsakymas (Response)</h4>
                    <dl class="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[10px]">
                        <dt class="text-gray-500 font-medium">Statusas</dt><dd class="break-all min-w-0" x-text="detailTrace.resp_code||detailTrace.status"></dd>
                        <template x-for="k in Object.keys(detailTrace.resp_headers||{})" :key="k"><dt class="text-gray-500" x-text="k"></dt><dd class="break-all" x-text="detailTrace.resp_headers[k]"></dd></template>
                    </dl>
                </div>

                <!-- Spans -->
                <div>
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">Etapai (Spans)</h4>
                    <div x-data="{openS:{}}">
                        <template x-for="(s,i) in spanTree(detailTrace,null)" :key="i">
                            <div>
                                <div class="flex items-center gap-1.5 py-0.5 text-xs cursor-pointer hover:bg-gray-50 rounded" @click="openS[s._i]=!openS[s._i]">
                                    <span class="w-5 text-center text-gray-400 shrink-0 text-[10px]" x-text="s._children.length?(openS[s._i]?'▾':'▸'):' '"></span>
                                    <span class="w-36 text-gray-600 shrink-0 truncate font-medium" :title="s.n" x-text="s.n"></span>
                                    <div class="flex-1 h-2.5 bg-gray-100 rounded overflow-hidden min-w-[40px]">
                                        <div class="h-full rounded" :class="s.ms>100?'bg-red-500':s.ms>30?'bg-yellow-400':'bg-green-500'" :style="'width:'+Math.max(1,Math.min(100,(s.ms||0)/detailTrace.total_ms*100))+'%'"></div>
                                    </div>
                                    <span class="w-14 text-right font-mono text-[10px] shrink-0" x-text="(s.ms??'—')+'ms'"></span>
                                    <span class="w-8 text-right text-[10px] text-gray-400 shrink-0" x-show="s._children.length" x-text="s._children.length+'↓'"></span>
                                </div>
                                <!-- Children (level 1) -->
                                <template x-if="openS[s._i]&&s._children.length">
                                    <div class="ml-6 border-l-2 border-gray-200 pl-2">
                                        <template x-for="(c,ci) in s._children" :key="ci">
                                            <div>
                                                <div class="flex items-center gap-1.5 py-0.5 text-xs cursor-pointer hover:bg-gray-50 rounded" @click="openS[c._i]=!openS[c._i]">
                                                    <span class="w-5 text-center text-gray-300 shrink-0 text-[10px]" x-text="c._children.length?(openS[c._i]?'▾':'▸'):' '"></span>
                                                    <span class="w-32 text-gray-500 shrink-0 truncate" :title="c.n" x-text="c.n"></span>
                                                    <div class="flex-1 h-2 bg-gray-100 rounded overflow-hidden min-w-[30px]">
                                                        <div class="h-full rounded" :class="c.ms>100?'bg-red-500':c.ms>30?'bg-yellow-400':'bg-green-500'" :style="'width:'+Math.max(1,Math.min(100,(c.ms||0)/detailTrace.total_ms*100))+'%'"></div>
                                                    </div>
                                                    <span class="w-14 text-right font-mono text-[10px] shrink-0" x-text="(c.ms??'—')+'ms'"></span>
                                                </div>
                                                <!-- Children (level 2+) -->
                                                <template x-if="openS[c._i]&&c._children.length">
                                                    <div class="ml-6 border-l border-gray-100 pl-2">
                                                        <template x-for="(gc,gci) in c._children" :key="gci">
                                                            <div class="flex items-center gap-1.5 py-0.5 text-xs">
                                                                <span class="w-5 shrink-0"></span>
                                                                <span class="w-28 text-gray-400 shrink-0 truncate" :title="gc.n" x-text="gc.n"></span>
                                                                <div class="flex-1 h-1.5 bg-gray-100 rounded overflow-hidden min-w-[20px]">
                                                                    <div class="h-full rounded" :class="gc.ms>100?'bg-red-500':gc.ms>30?'bg-yellow-400':'bg-green-500'" :style="'width:'+Math.max(1,Math.min(100,(gc.ms||0)/detailTrace.total_ms*100))+'%'"></div>
                                                                </div>
                                                                <span class="w-14 text-right font-mono text-[10px] shrink-0" x-text="(gc.ms??'—')+'ms'"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- DB Queries -->
                <!-- DB Queries nested under spans -->
                <div x-show="detailTrace.db&&detailTrace.db.length" x-data="{qf:'',qs:false,qdup:false,qview:'tree',openQ:{}}">
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2" x-text="'DB užklausos ('+detailTrace.db_n+', '+detailTrace.db_ms+'ms)'"></h4>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <input type="text" class="border border-gray-300 rounded px-2 py-0.5 text-[10px] w-52" x-model="qf" placeholder="Filtruoti SQL / kvietėją...">
                        <label class="flex items-center gap-1 text-[10px]"><input type="checkbox" x-model="qs"> &gt;5ms</label>
                        <label class="flex items-center gap-1 text-[10px]"><input type="checkbox" x-model="qdup"> Grupuoti</label>
                        <div class="flex gap-0.5 ml-2">
                            <button class="px-2 py-0.5 rounded text-[10px]" :class="qview==='tree'?'bg-blue-100 text-blue-700':'bg-gray-100'" @click="qview='tree'">Medis</button>
                            <button class="px-2 py-0.5 rounded text-[10px]" :class="qview==='flat'?'bg-blue-100 text-blue-700':'bg-gray-100'" @click="qview='flat'">Sąrašas</button>
                        </div>
                    </div>

                    <!-- Tree view: queries grouped under spans -->
                    <div x-show="qview==='tree'" class="max-h-[500px] overflow-auto">
                        <template x-for="(s,si) in (detailTrace.spans||[])" :key="si">
                            <div x-show="spanQueries(si).length>0" class="mb-2">
                                <div class="flex items-center gap-2 px-2 py-1 bg-gray-50 rounded text-[11px] font-medium text-gray-600">
                                    <span x-text="s.n"></span>
                                    <span class="text-gray-400" x-text="spanQueries(si).length+' užkl.'"></span>
                                    <span class="font-mono text-gray-400" x-text="spanQueries(si).reduce((a,q)=>a+q.ms,0).toFixed(1)+'ms'"></span>
                                </div>
                                <template x-for="(q,qi) in spanQueries(si)" :key="qi">
                                    <div class="border-l-2 border-gray-200 ml-2 pl-2 py-0.5">
                                        <div class="flex items-start gap-2 cursor-pointer hover:bg-gray-50 rounded px-1" @click="openQ[si+'_'+qi]=!openQ[si+'_'+qi]">
                                            <span class="text-gray-400 text-[10px] shrink-0" x-text="openQ[si+'_'+qi]?'▾':'▸'"></span>
                                            <span class="font-mono font-semibold text-[10px] shrink-0 w-12 text-right" :class="q.ms>10?'text-red-600':q.ms>5?'text-yellow-600':'text-gray-600'" x-text="q.ms+'ms'"></span>
                                            <code class="text-[10px] break-all text-gray-700 flex-1" x-text="q.sql.substring(0,120)+(q.sql.length>120?'…':'')"></code>
                                            <span class="text-[10px] text-gray-400 shrink-0" x-show="q._c>1" x-text="'×'+q._c"></span>
                                        </div>
                                        <!-- Expanded: full SQL + call stack -->
                                        <div x-show="openQ[si+'_'+qi]" class="ml-6 mt-1 mb-2 space-y-1">
                                            <pre class="bg-gray-900 text-green-300 p-2 rounded text-[10px] overflow-auto max-h-32 whitespace-pre-wrap" x-text="q.sql"></pre>
                                            <div class="text-[10px]">
                                                <span class="font-semibold text-gray-600">Kvietimų grandinė:</span>
                                                <div class="mt-0.5 pl-2 border-l border-gray-200 space-y-0">
                                                    <template x-for="(fn,fi) in (q.stack||q.caller.split(', '))" :key="fi">
                                                        <div class="flex items-center gap-1 py-0">
                                                            <span class="text-gray-300">→</span>
                                                            <code class="text-[10px]" :class="fi===(q.stack||q.caller.split(', ')).length-1?'text-blue-600 font-semibold':'text-gray-500'" x-text="fn"></code>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                            <div x-show="q._c>1" class="text-[10px] text-gray-500">
                                                Vykdyta <strong x-text="q._c"></strong> kartų, max: <strong x-text="q.ms+'ms'"></strong>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <!-- Queries without span association -->
                        <div x-show="unassignedQueries().length>0" class="mb-2">
                            <div class="flex items-center gap-2 px-2 py-1 bg-yellow-50 rounded text-[11px] font-medium text-gray-600">
                                <span>Nepriskirtos užklausos</span>
                                <span class="text-gray-400" x-text="unassignedQueries().length+' užkl.'"></span>
                            </div>
                            <template x-for="(q,qi) in unassignedQueries()" :key="qi">
                                <div class="border-l-2 border-yellow-200 ml-2 pl-2 py-0.5">
                                    <div class="flex items-start gap-2 cursor-pointer hover:bg-gray-50 rounded px-1" @click="openQ['u_'+qi]=!openQ['u_'+qi]">
                                        <span class="text-gray-400 text-[10px]" x-text="openQ['u_'+qi]?'▾':'▸'"></span>
                                        <span class="font-mono font-semibold text-[10px] w-12 text-right" :class="q.ms>10?'text-red-600':'text-gray-600'" x-text="q.ms+'ms'"></span>
                                        <code class="text-[10px] break-all text-gray-700 flex-1" x-text="q.sql.substring(0,120)"></code>
                                    </div>
                                    <div x-show="openQ['u_'+qi]" class="ml-6 mt-1 mb-2 space-y-1">
                                        <pre class="bg-gray-900 text-green-300 p-2 rounded text-[10px] overflow-auto max-h-32 whitespace-pre-wrap" x-text="q.sql"></pre>
                                        <div class="text-[10px] pl-2 border-l border-gray-200">
                                            <template x-for="(fn,fi) in (q.stack||q.caller.split(', '))" :key="fi">
                                                <div class="flex items-center gap-1"><span class="text-gray-300">→</span><code class="text-[10px]" :class="fi===(q.stack||q.caller.split(', ')).length-1?'text-blue-600 font-semibold':'text-gray-500'" x-text="fn"></code></div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Flat view -->
                    <div x-show="qview==='flat'" class="max-h-96 overflow-auto">
                        <table class="w-full text-[10px]">
                        <thead class="bg-gray-50 sticky top-0"><tr>
                            <th class="px-1.5 py-1 text-left w-14">ms</th>
                            <th class="px-1.5 py-1 text-left">SQL</th>
                            <th class="px-1.5 py-1 text-left w-44">Kvietėjas</th>
                            <th class="px-1.5 py-1 text-center w-8" x-show="qdup">#</th>
                        </tr></thead>
                        <tbody>
                        <template x-for="(q,qi) in fq(detailTrace.db,qf,qs,qdup,'ms','desc')" :key="qi">
                            <tr class="border-b border-gray-50" :class="q.ms>10?'bg-red-50':q.ms>5?'bg-yellow-50':''">
                                <td class="px-1.5 py-1 font-mono font-semibold" x-text="q.ms"></td>
                                <td class="px-1.5 py-1"><code class="break-all" x-text="q.sql"></code></td>
                                <td class="px-1.5 py-1 text-gray-400 break-all" x-text="q.caller"></td>
                                <td class="px-1.5 py-1 text-center text-gray-400" x-show="qdup" x-text="q._c||1"></td>
                            </tr>
                        </template>
                        </tbody>
                        </table>
                    </div>
                </div>
                <p x-show="!detailTrace.db||!detailTrace.db.length" class="text-gray-400 text-xs">Nėra DB užklausų (SAVEQUERIES išjungtas?)</p>

                <!-- Hook traces -->
                <div x-show="detailTrace.hooks&&detailTrace.hooks.length" class="mt-4">
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">
                        WP Hook sekimas
                        <span class="font-normal text-gray-400 ml-2" x-text="(detailTrace.hooks||[]).length+' lėtų hook\'ų'"></span>
                    </h4>
                    <div x-data="{hf:''}">
                        <input type="text" class="border border-gray-300 rounded px-2 py-0.5 text-[10px] w-64 mb-2" x-model="hf" placeholder="Filtruoti hook / failą...">
                        <div class="overflow-auto max-h-[400px]">
                            <table class="w-full text-[10px] border-collapse">
                                <thead><tr class="bg-gray-50 text-left">
                                    <th class="px-2 py-1 font-semibold">Hook</th>
                                    <th class="px-2 py-1 font-semibold w-16 text-right">ms</th>
                                    <th class="px-2 py-1 font-semibold">Argumentai</th>
                                    <th class="px-2 py-1 font-semibold">Kvietėjas</th>
                                </tr></thead>
                                <tbody>
                                <template x-for="(h,hi) in (detailTrace.hooks||[]).filter(h=>!hf||h.hook.includes(hf)||h.caller.includes(hf))" :key="hi">
                                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                                        <td class="px-2 py-1 font-mono" x-text="h.hook"></td>
                                        <td class="px-2 py-1 text-right font-mono" :class="h.ms>100?'text-red-600 font-bold':h.ms>10?'text-yellow-600':'text-gray-600'" x-text="h.ms"></td>
                                        <td class="px-2 py-1 text-gray-500 max-w-xs truncate" :title="JSON.stringify(h.args)" x-text="(h.args||[]).join(' | ')"></td>
                                        <td class="px-2 py-1 text-gray-400 font-mono text-[9px]" x-text="h.caller"></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div x-show="!(detailTrace.hooks&&detailTrace.hooks.length)" class="mt-2 text-xs text-gray-400">
                    Hook sekimas išjungtas. Įjunkite <em>Nustatymai → Hook argumentų sekimas</em>.
                </div>

                <!-- Profiler (Excimer) -->
                <div x-show="detailTrace.profile&&detailTrace.profile.length" class="mt-4">
                    <h4 class="text-xs font-semibold text-gray-700 border-b border-gray-200 pb-1 mb-2">
                        Profilis (Excimer sampling)
                        <span class="font-normal text-gray-400 ml-2" x-text="detailTrace.profile_samples+' pavyzdžių'"></span>
                    </h4>
                    <div x-data="{pf:'',ps:'self_ms',pd:'desc'}" class="space-y-2">
                        <input type="text" class="border border-gray-300 rounded px-2 py-0.5 text-[10px] w-64" x-model="pf" placeholder="Filtruoti funkciją / failą...">
                        <div class="overflow-auto max-h-[400px]">
                            <table class="w-full text-[10px]">
                            <thead class="bg-gray-50 sticky top-0"><tr>
                                <th class="px-1.5 py-1 text-left cursor-pointer select-none" @click="ps='fn';pd=pd==='desc'?'asc':'desc'">Funkcija</th>
                                <th class="px-1.5 py-1 text-left w-48 cursor-pointer select-none" @click="ps='file';pd=pd==='desc'?'asc':'desc'">Failas:eilutė</th>
                                <th class="px-1.5 py-1 text-right w-16 cursor-pointer select-none" @click="ps='self_ms';pd=pd==='desc'?'asc':'desc'">Self ms</th>
                                <th class="px-1.5 py-1 text-right w-16 cursor-pointer select-none" @click="ps='total_ms';pd=pd==='desc'?'asc':'desc'">Total ms</th>
                                <th class="px-1.5 py-1 text-right w-12 cursor-pointer select-none" @click="ps='samples';pd=pd==='desc'?'asc':'desc'">#</th>
                                <th class="px-1.5 py-1 w-32">Self %</th>
                            </tr></thead>
                            <tbody>
                            <template x-for="(p,pi) in profileFiltered(pf,ps,pd)" :key="pi">
                                <tr class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-1.5 py-1 font-mono font-medium text-blue-700" x-text="p.fn||'(main)'"></td>
                                    <td class="px-1.5 py-1 text-gray-400 truncate max-w-[200px]" :title="p.file+':'+p.line" x-text="shortPath(p.file)+':'+p.line"></td>
                                    <td class="px-1.5 py-1 text-right font-mono font-semibold" :class="p.self_ms>50?'text-red-600':p.self_ms>10?'text-yellow-600':''" x-text="p.self_ms"></td>
                                    <td class="px-1.5 py-1 text-right font-mono text-gray-500" x-text="p.total_ms"></td>
                                    <td class="px-1.5 py-1 text-right text-gray-400" x-text="p.samples"></td>
                                    <td class="px-1.5 py-1">
                                        <div class="h-2 bg-gray-100 rounded overflow-hidden">
                                            <div class="h-full rounded" :class="p.self_ms>50?'bg-red-500':p.self_ms>10?'bg-yellow-400':'bg-blue-400'" :style="'width:'+Math.min(100,(p.self_ms/detailTrace.total_ms*100))+'%'"></div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            </template>
        </div>
        </div>

        <script>
        function vltT(){return{
            tab:'live',live:[],maxT:<?php echo $max; ?>,
            sc:'total_ms',sd:'desc',detailTrace:null,evtSource:null,sseConnected:false,
            expDate:'',expData:[],esc:'total_ms',esd:'desc',
            ef:{method:'',uri:'',status:'',ip:'',minMs:0,maxMs:0,minDb:0,minDbMs:0,minMem:'',user:'',co:''},

            init(){
                this.startSSE();
                // Initial load from REST as fallback
                this.goLive();
            },
            startSSE(){
                if(this.evtSource)this.evtSource.close();
                this.evtSource=new EventSource('<?php echo $sse_url; ?>');
                this.evtSource.onopen=()=>{this.sseConnected=true};
                this.evtSource.onmessage=(e)=>{
                    try{
                        const items=JSON.parse(e.data);
                        if(!items||!items.length)return;
                        const ids=new Set(this.live.map(t=>t.id));
                        const fresh=items.filter(t=>t.id&&!ids.has(t.id));
                        if(fresh.length)this.live=[...fresh,...this.live].slice(0,500);
                    }catch(err){}
                };
                this.evtSource.onerror=()=>{this.sseConnected=false};
            },
            async goLive(){const d=await this.rpc('live');if(d)this.live=d},
            async loadExp(){if(!this.expDate)return;const d=await this.rpc('history',{date:this.expDate});if(d)this.expData=d.reverse()},
            get currentRows(){return this.tab==='explorer'?this.expFiltered:this.live},

            ss(c){if(this.sc===c)this.sd=this.sd==='asc'?'desc':'asc';else{this.sc=c;this.sd='desc'}},
            si(c){return this.sc===c?(this.sd==='asc'?'▲':'▼'):''},
            get liveSorted(){return this.doSort(this.live,this.sc,this.sd)},

            es(c){if(this.esc===c)this.esd=this.esd==='asc'?'desc':'asc';else{this.esc=c;this.esd='desc'}},
            esi(c){return this.esc===c?(this.esd==='asc'?'▲':'▼'):''},
            get expFiltered(){return this.applyFilter(this.expData,this.ef)},
            get expSorted(){return this.doSort(this.expFiltered,this.esc,this.esd)},

            doSort(arr,col,dir){
                return[...arr].sort((a,b)=>{
                    let va=a[col]??'',vb=b[col]??'';
                    const cmp=typeof va==='number'&&typeof vb==='number'?va-vb:String(va).localeCompare(String(vb));
                    return dir==='asc'?cmp:-cmp;
                });
            },
            applyFilter(arr,f){
                let r=arr;
                if(f.method)r=r.filter(t=>t.method===f.method);
                if(f.uri)r=r.filter(t=>(t.uri||'').toLowerCase().includes(f.uri.toLowerCase()));
                if(f.status)r=r.filter(t=>String(t.status).startsWith(f.status));
                if(f.ip)r=r.filter(t=>(t.ip||'').includes(f.ip));
                if(f.minMs)r=r.filter(t=>t.total_ms>=f.minMs);
                if(f.maxMs)r=r.filter(t=>t.total_ms<=f.maxMs);
                if(f.minDb)r=r.filter(t=>t.db_n>=f.minDb);
                if(f.minDbMs)r=r.filter(t=>t.db_ms>=f.minDbMs);
                if(f.minMem){const mb=parseFloat(f.minMem)*1048576;if(mb)r=r.filter(t=>t.mem>=mb)}
                if(f.user){const u=f.user.toLowerCase();r=r.filter(t=>String(t.user||'').includes(u)||(t.user_name||'').toLowerCase().includes(u)||(t.user_email||'').toLowerCase().includes(u))}
                if(f.co)r=r.filter(t=>(t.cf_co||'').toLowerCase().includes(f.co.toLowerCase()));
                return r;
            },
            openDetail(t){this.detailTrace=t;this.tab='detail'},

            profileFiltered(pf,ps,pd){
                if(!this.detailTrace||!this.detailTrace.profile)return[];
                let p=[...this.detailTrace.profile];
                if(pf){const f=pf.toLowerCase();p=p.filter(x=>(x.fn||'').toLowerCase().includes(f)||(x.file||'').toLowerCase().includes(f))}
                p.sort((a,b)=>{const va=a[ps]??'',vb=b[ps]??'';const cmp=typeof va==='number'?va-vb:String(va).localeCompare(String(vb));return pd==='asc'?cmp:-cmp});
                return p;
            },
            shortPath(f){if(!f)return'';const p=f.split('/');return p.length>3?'…/'+p.slice(-3).join('/'):f},

            // Get queries associated with a specific span index
            spanQueries(spanIdx){
                if(!this.detailTrace||!this.detailTrace.db)return[];
                let q=this.detailTrace.db.filter(q=>q.span===spanIdx);
                // If no span association data, fall back to matching by caller containing span name
                if(!q.length&&this.detailTrace.spans&&this.detailTrace.spans[spanIdx]){
                    const name=this.detailTrace.spans[spanIdx].n;
                    if(name&&name!=='request')q=this.detailTrace.db.filter(q=>(q.caller||'').toLowerCase().includes(name.toLowerCase()));
                }
                return q;
            },
            unassignedQueries(){
                if(!this.detailTrace||!this.detailTrace.db)return[];
                const assigned=new Set();
                (this.detailTrace.spans||[]).forEach((s,i)=>{
                    this.spanQueries(i).forEach(q=>assigned.add(q));
                });
                return this.detailTrace.db.filter(q=>!assigned.has(q));
            },

            spanTree(t,parentIdx){
                if(!t||!t.spans)return[];
                const byParent={};
                t.spans.forEach((s,i)=>{
                    const key=s.p===null||s.p===undefined?'_root':String(s.p);
                    if(!byParent[key])byParent[key]=[];
                    byParent[key].push({...s,_i:i});
                });
                const build=(pid)=>(byParent[pid]||[]).map(s=>({...s,_children:build(String(s._i))}));
                return build(parentIdx===null?'_root':String(parentIdx));
            },
            spanTree_children(){return[]},
            fq(db,qf,qs,qdup,qsc,qsd){
                if(!db)return[];let q=[...db];
                if(qs)q=q.filter(x=>x.ms>5);
                if(qf){const f=qf.toLowerCase();q=q.filter(x=>(x.sql||'').toLowerCase().includes(f)||(x.caller||'').toLowerCase().includes(f))}
                if(qdup){const m={};q.forEach(x=>{const k=(x.sql||'').replace(/\d+/g,'?').replace(/'[^']*'/g,'?');if(!m[k])m[k]={...x,_c:1};else{m[k]._c++;m[k].ms=Math.max(m[k].ms,x.ms)}});q=Object.values(m)}
                q.sort((a,b)=>{const va=a[qsc]??'',vb=b[qsc]??'';const cmp=typeof va==='number'?va-vb:String(va).localeCompare(String(vb));return qsd==='asc'?cmp:-cmp});
                return q;
            },
            async rpc(s,p={}){try{const r=await fetch('<?php echo $rest_url; ?>/tracer/'+s+'?'+new URLSearchParams(p),{headers:{'X-WP-Nonce':'<?php echo $nonce; ?>'}});const d=await r.json();return d.error?null:d}catch(e){return null}},
            sz(b){if(!b)return'—';if(b>=1048576)return(b/1048576).toFixed(1)+'MB';if(b>=1024)return(b/1024).toFixed(0)+'KB';return b+'B'}
        }}
        </script>
        <?php
    }
}
