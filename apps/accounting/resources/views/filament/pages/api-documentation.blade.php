<x-filament-panels::page>
    @php
        $endpoints = $this->getEndpoints();
        $baseUrl = url('/api');
    @endphp

    {{-- Top info card --}}
    <div class="ak-doc-banner">
        <div class="ak-doc-banner-grid">
            <div>
                <span class="ak-eyebrow">Base URL</span>
                <div class="ak-doc-mono">{{ $baseUrl }}</div>
            </div>
            <div>
                <span class="ak-eyebrow">Versi</span>
                <div class="ak-doc-mono">v1</div>
            </div>
            <div>
                <span class="ak-eyebrow">Format</span>
                <div class="ak-doc-mono">JSON · UTF-8</div>
            </div>
            <div>
                <span class="ak-eyebrow">Auth</span>
                <div class="ak-doc-mono">Bearer Token</div>
            </div>
        </div>
        <div class="ak-doc-banner-tip">
            <strong>Cara cepat mulai:</strong>
            generate token di
            <a href="{{ \App\Filament\Resources\ApiTokenResource::getUrl('index') }}" class="ak-doc-link">API → Teknis</a>,
            tetapkan scope app + permission yang dibutuhkan, lalu kirim request dengan header
            <code class="ak-doc-inline">Authorization: Bearer &lt;token&gt;</code>.
        </div>
    </div>

    {{-- Endpoint list --}}
    <div class="ak-doc-toc">
        <span class="ak-eyebrow">§ Endpoints</span>
        <ul>
            @foreach ($endpoints as $i => $ep)
                <li>
                    <a href="#ep-{{ $i }}" class="ak-doc-toc-link">
                        <span class="ak-doc-method ak-doc-method--{{ strtolower($ep['method']) }}">{{ $ep['method'] }}</span>
                        <span class="ak-doc-path">{{ $ep['path'] }}</span>
                        <span class="ak-doc-toc-name">{{ $ep['name'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    @foreach ($endpoints as $i => $ep)
        @php
            $reqJson = json_encode($ep['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $resJson = json_encode($ep['response']['success']['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $curl = "curl -X {$ep['method']} '" . $baseUrl . $ep['path'] . "' \\\n"
                  . "  -H 'Authorization: Bearer YOUR_TOKEN' \\\n"
                  . "  -H 'Content-Type: application/json' \\\n"
                  . "  -d '" . $reqJson . "'";

            // Build PHP example string — split '<?php' to avoid literal in source
            $phpExample = '<' . "?php\n"
                . "\$response = Http::withToken(env('AKUNTA_TOKEN'))\n"
                . "    ->acceptJson()\n"
                . "    ->post('" . $baseUrl . $ep['path'] . "', " . var_export($ep['request'], true) . ");\n\n"
                . "if (\$response->successful()) {\n"
                . "    \$journalId = \$response->json('journal_id');\n"
                . "}";
        @endphp

        <section id="ep-{{ $i }}" class="ak-doc-endpoint" x-data="{ tab: 'curl' }">
            <header class="ak-doc-endpoint-head">
                <div class="ak-doc-endpoint-title">
                    <span class="ak-doc-method ak-doc-method--{{ strtolower($ep['method']) }}">{{ $ep['method'] }}</span>
                    <span class="ak-doc-path">{{ $ep['path'] }}</span>
                </div>
                <h2 class="ak-doc-endpoint-name">{{ $ep['name'] }}</h2>
                <p class="ak-doc-endpoint-desc">{{ $ep['desc'] }}</p>
            </header>

            <div class="ak-doc-endpoint-meta">
                <div>
                    <span class="ak-eyebrow">Authentikasi</span>
                    <div class="ak-doc-meta-val">{{ $ep['auth'] }}</div>
                </div>
                <div>
                    <span class="ak-eyebrow">Permission</span>
                    <div class="ak-doc-meta-val ak-doc-mono">{{ $ep['perms'] }}</div>
                </div>
                <div>
                    <span class="ak-eyebrow">Rate Limit</span>
                    <div class="ak-doc-meta-val">{{ $ep['rate'] }}</div>
                </div>
            </div>

            <div class="ak-doc-section">
                <div class="ak-doc-section-head">
                    <span class="ak-eyebrow">Contoh Request</span>
                    <div class="ak-doc-tabs">
                        <button type="button" class="ak-doc-tab" :class="tab === 'curl' ? 'ak-doc-tab--active' : ''" @click="tab='curl'">cURL</button>
                        <button type="button" class="ak-doc-tab" :class="tab === 'json' ? 'ak-doc-tab--active' : ''" @click="tab='json'">JSON</button>
                        <button type="button" class="ak-doc-tab" :class="tab === 'php'  ? 'ak-doc-tab--active' : ''" @click="tab='php'">PHP</button>
                    </div>
                </div>

                <div x-show="tab==='curl'" x-cloak>
                    <pre class="ak-doc-code"><code>{{ $curl }}</code></pre>
                </div>

                <div x-show="tab==='json'" x-cloak>
                    <pre class="ak-doc-code"><code>{{ $reqJson }}</code></pre>
                </div>

                <div x-show="tab==='php'" x-cloak>
                    <pre class="ak-doc-code"><code>{{ $phpExample }}</code></pre>
                </div>
            </div>

            <div class="ak-doc-section">
                <span class="ak-eyebrow">Response — 201 Created</span>
                <pre class="ak-doc-code ak-doc-code--success"><code>{{ $resJson }}</code></pre>
            </div>

            <div class="ak-doc-section">
                <span class="ak-eyebrow">Response — Error codes</span>
                <table class="ak-doc-error-table">
                    <thead>
                        <tr>
                            <th>HTTP</th>
                            <th>Error code</th>
                            <th>Kapan terjadi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ep['response']['errors'] as $err)
                            <tr>
                                <td><span class="ak-doc-status ak-doc-status--{{ ((int) $err['code']) >= 500 ? 'err' : (((int) $err['code']) >= 400 ? 'warn' : 'ok') }}">{{ $err['code'] }}</span></td>
                                <td class="ak-doc-mono">{{ $err['error'] }}</td>
                                <td>{{ $err['when'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    {{-- Footer notes --}}
    <div class="ak-doc-notes">
        <span class="ak-eyebrow">§ Catatan</span>
        <ul>
            <li><strong>Idempotency:</strong> kirim header atau field <code class="ak-doc-inline">idempotency_key</code> untuk safe-retry. Server akan tolak duplikat dengan <code class="ak-doc-inline">409</code>.</li>
            <li><strong>Tanggal:</strong> format ISO <code class="ak-doc-inline">YYYY-MM-DD</code>. Periode open harus mencakup tanggal jurnal.</li>
            <li><strong>Sisi Debit/Kredit:</strong> setiap baris hanya boleh punya nilai pada satu sisi. Total D = total K (selisih < 0.005).</li>
            <li><strong>Source app:</strong> wajib match dengan <code class="ak-doc-inline">app_id</code> yang ditetapkan di token.</li>
        </ul>
    </div>
</x-filament-panels::page>
