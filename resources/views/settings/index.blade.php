@extends('layouts.app')

@section('title', 'Connection')
@section('subtitle', 'How this server reaches the ZKTeco terminal')

@section('content')
<div class="grid-2">
    <div class="card">
        <div class="card-header"><div><h2>Terminal connection</h2><p>Used by the website, the live listener and the scheduled sync.</p></div></div>
        <div class="card-body">
            <form method="POST" action="{{ route('settings.update') }}" id="settingsForm">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="cfg_ip">Terminal IP address</label>
                    <input class="input" type="text" name="ip" id="cfg_ip" value="{{ old('ip', $settings['ip']) }}" placeholder="192.168.1.5" required>
                    <span class="help">Reserve this address for the terminal in your router so it never changes.</span>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="cfg_port">Port</label>
                        <input class="input" type="number" name="port" id="cfg_port" value="{{ old('port', $settings['port']) }}" min="1" max="65535" required>
                        <span class="help">Usually 4370.</span>
                    </div>
                    <div class="field">
                        <label for="cfg_timeout">Timeout (seconds)</label>
                        <input class="input" type="number" name="timeout_sec" id="cfg_timeout" value="{{ old('timeout_sec', $settings['timeout_sec']) }}" min="1" max="60" required>
                        <span class="help">5 on a cable, 10–15 over Wi-Fi.</span>
                    </div>
                </div>
                <div class="field">
                    <span class="field-label">Protocol</span>
                    <div class="protocols">
                        @foreach (['tcp' => 'Recommended. Reliable for large log downloads.', 'udp' => 'Older mode, if TCP is blocked.', 'auto' => 'Tries TCP first, then UDP.'] as $value => $hint)
                            <label class="protocol">
                                <input type="radio" name="protocol" value="{{ $value }}" @checked(old('protocol', $settings['protocol']) === $value)>
                                <span><strong>{{ strtoupper($value) }}</strong><small>{{ $hint }}</small></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="field">
                    <label for="cfg_comm_key">Communication key</label>
                    <input class="input" type="number" name="comm_key" id="cfg_comm_key" value="{{ old('comm_key', $settings['comm_key']) }}" min="0">
                    <span class="help">The password set in the terminal's Comm menu. Leave 0 if none.</span>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px;">
                    <button type="submit" class="btn btn-primary">Save settings</button>
                    <button type="button" class="btn btn-secondary" id="btnTest" onclick="runConnectionTest()">Test connection</button>
                    <button type="button" class="btn btn-ghost" onclick="resetDefaultSettings()">Reset to defaults</button>
                </div>
            </form>
            <div id="testResultBox" style="margin-top: 16px;"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>TCP or UDP?</h2></div>
        <div class="card-body">
            <ul class="guide">
                <li><strong>TCP</strong> delivers data in order and is the safest choice when downloading thousands of punches.</li>
                <li><strong>UDP</strong> is the older ZKTeco mode. Use it only if something blocks TCP port 4370.</li>
                <li><strong>Timeout:</strong> 3–5 seconds on a cable. Increase to 10–15 seconds over Wi-Fi or VPN.</li>
                <li>The terminal must be on the same network as this server. Never expose port 4370 to the internet.</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const defaults = @json(config('zkteco'));

    function resetDefaultSettings() {
        document.getElementById('cfg_ip').value = defaults.ip;
        document.getElementById('cfg_port').value = defaults.port;
        document.getElementById('cfg_timeout').value = defaults.timeout_sec;
        document.getElementById('cfg_comm_key').value = defaults.comm_key;
        const radio = document.querySelector(`input[name="protocol"][value="${defaults.protocol}"]`);
        if (radio) radio.checked = true;
    }

    async function runConnectionTest() {
        const form = document.getElementById('settingsForm');
        const box = document.getElementById('testResultBox');
        const btn = document.getElementById('btnTest');
        const values = Object.fromEntries(new FormData(form));
        btn.disabled = true;
        box.innerHTML = `<div class="alert alert-info">Contacting ${escapeHtml(values.ip)}:${escapeHtml(values.port)}…</div>`;

        try {
            const data = await postJson(@json(route('settings.test')), values);
            if (data.success) {
                box.innerHTML = `<div class="alert alert-success">
                    <strong>${escapeHtml(data.message)}</strong> (${data.latency_ms} ms)
                    <div class="result-tags">
                        <span class="badge badge-in">Model: ${escapeHtml(data.deviceName)}</span>
                        <span class="badge badge-in">S/N: ${escapeHtml(data.serialNumber)}</span>
                        <span class="badge badge-in">Firmware: ${escapeHtml(data.version)}</span>
                        <span class="badge badge-in">Terminal time: ${escapeHtml(data.deviceTime)}</span>
                    </div></div>`;
            } else {
                box.innerHTML = `<div class="alert alert-danger"><strong>${escapeHtml(data.message)}</strong><div style="font-size: 12.5px; margin-top: 4px;">Tried for ${data.latency_ms} ms. Check that the terminal is on and connected to the network.</div></div>`;
            }
        } catch (err) {
            box.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
        } finally {
            btn.disabled = false;
        }
    }
</script>
@endpush
