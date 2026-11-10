@extends('layouts.app')

@section('title', 'Device')
@section('subtitle', 'Hardware details, storage and maintenance')

@section('content')
@php
    $show = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $count = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $recUsed = isset($sizes['records']) ? (int) $sizes['records'] : null;
    $recCap = isset($sizes['records_cap']) ? (int) $sizes['records_cap'] : null;
    $recFree = ($recUsed !== null && $recCap !== null) ? $recCap - $recUsed : null;
    $recPct = $recCap ? round($recUsed / $recCap * 100, 1) : null;
    $tone = $recPct > 90 ? 'danger' : ($recPct > 75 ? 'warning' : 'success');
    $face = $state['faceFunction'] ?? null;
@endphp

<div class="grid-2">
    <div class="stack">
        <div class="card">
            <div class="card-header"><h2>Hardware &amp; firmware</h2></div>
            <table class="kv">
                <tr><th>Model</th><td><strong>{{ $show($state['deviceName'] ?? null) }}</strong></td></tr>
                <tr><th>Serial number</th><td><code>{{ $show($state['serialNumber'] ?? null) }}</code></td></tr>
                <tr><th>Firmware / FP algorithm</th><td>{{ $show($state['fmVersion'] ?? null) }}</td></tr>
                <tr><th>SDK version</th><td>{{ $show($state['version'] ?? null) }}</td></tr>
                <tr><th>Platform</th><td>{{ $show($state['platform'] ?? null) }} (OS {{ $show($state['osversion'] ?? null) }})</td></tr>
                <tr><th>SSR / work code</th><td>{{ $show($state['ssr'] ?? null) }} / {{ $show($state['workCode'] ?? null) }}</td></tr>
                <tr><th>PIN length</th><td>{{ $show($state['pinWidth'] ?? null) }} digits</td></tr>
                <tr><th>Face recognition</th><td>{{ $face === null ? '—' : ($face ? 'Yes' : 'No') }}</td></tr>
                <tr><th>Last seen</th><td>{{ $show($state['last_seen'] ?? null) }}</td></tr>
            </table>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Storage</h2>
                <span class="text-muted">Read {{ $show($state['sizes_at'] ?? null) }}</span>
            </div>
            <div class="card-body">
                @if ($recPct !== null)
                    <div style="display: flex; justify-content: space-between; font-weight: 600;">
                        <span>Attendance logs</span>
                        <span class="text-{{ $tone }}">{{ $recPct }}% used</span>
                    </div>
                    <div class="meter"><span style="width: {{ min(100, $recPct) }}%; background: var(--{{ $tone }});"></span></div>
                    @if ($recPct >= 90)
                        <div class="alert alert-warning" style="margin-top: 14px;">Only {{ number_format($recFree) }} log slots are left. Run a sync, then clear the logs on the terminal so new punches are not lost.</div>
                    @endif
                @endif
            </div>
            <table class="kv">
                <tr><th>Logs stored</th><td><strong>{{ $count($recUsed) }} / {{ $count($recCap) }}</strong></td></tr>
                <tr><th>Free log slots</th><td class="text-{{ $tone }}"><strong>{{ $count($recFree) }}</strong></td></tr>
                <tr><th>Users enrolled</th><td>{{ number_format($employeeCount) }} / {{ $count($sizes['users_cap'] ?? null) }}</td></tr>
                <tr><th>Fingerprints enrolled</th><td>{{ $count($sizes['fingers'] ?? null) }} / {{ $count($sizes['fingers_cap'] ?? null) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-header"><div><h2>Clock</h2><p>Keep the terminal's clock right so punch times are accurate.</p></div></div>
            <div class="card-body">
                <div class="clock-box">
                    <div><span class="text-muted">Server time</span><strong id="pcTimeDisplay">{{ now()->format('Y-m-d H:i:s') }}</strong></div>
                    <div><span class="text-muted">Terminal time</span><strong id="devTimeDisplay">Checking…</strong></div>
                </div>
                @if (auth()->user()->isAdmin())
                    <button class="btn btn-secondary btn-block" id="btnSyncTime" onclick="syncDeviceTime()">Set terminal clock to server time</button>
                    <div class="inline-status" id="syncTimeStatus"></div>
                @endif
            </div>
        </div>

        @if (auth()->user()->isAdmin())
            <div class="card">
                <div class="card-header"><div><h2>Maintenance</h2><p>Commands sent straight to the terminal.</p></div></div>
                <div class="card-body" style="display: grid; gap: 10px;">
                    <button class="btn btn-secondary" id="btnVoiceTest" onclick="testDeviceVoice()">Test terminal speaker</button>
                    <button class="btn btn-danger" id="btnRestartDevice" onclick="restartDevicePrompt()">Reboot terminal</button>
                    <div class="inline-status" id="hardwareControlStatus"></div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Server clock ticks locally; the terminal's comes from the status check in the top bar
    const serverStart = new Date(@json(now()->format('Y-m-d\TH:i:s'))).getTime(), loadedAt = Date.now();
    setInterval(() => {
        const d = new Date(serverStart + Date.now() - loadedAt), p = n => String(n).padStart(2, '0');
        document.getElementById('pcTimeDisplay').textContent = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
        document.getElementById('devTimeDisplay').textContent = document.getElementById('deviceClock').textContent;
    }, 1000);

    function setStatus(id, ok, message) {
        document.getElementById(id).innerHTML = `<span class="${ok ? 'text-success' : 'text-danger'}"><strong>${escapeHtml(message)}</strong></span>`;
    }

    async function runButton(btnId, busyText, statusId, url, onSuccess) {
        const btn = document.getElementById(btnId);
        const label = btn.textContent;
        btn.disabled = true;
        btn.textContent = busyText;
        document.getElementById(statusId).textContent = '';
        try {
            const data = await postJson(url);
            data.success ? onSuccess(data) : setStatus(statusId, false, data.message);
        } catch (err) {
            setStatus(statusId, false, err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = label;
        }
    }

    function syncDeviceTime() {
        runButton('btnSyncTime', 'Setting clock…', 'syncTimeStatus', @json(route('device.time')), data => {
            setStatus('syncTimeStatus', true, `Terminal clock set to ${data.time}`);
            DeviceStatus.refresh(true);
        });
    }

    function testDeviceVoice() {
        runButton('btnVoiceTest', 'Sending…', 'hardwareControlStatus', @json(route('device.voice')), data => setStatus('hardwareControlStatus', true, data.message));
    }

    function restartDevicePrompt() {
        if (!confirm('Reboot the terminal? It will be offline for about a minute and nobody can punch in meanwhile.')) return;
        runButton('btnRestartDevice', 'Sending reboot…', 'hardwareControlStatus', @json(route('device.restart')), data => {
            document.getElementById('hardwareControlStatus').innerHTML = `<span class="text-warning"><strong>${escapeHtml(data.message)}</strong></span>`;
        });
    }
</script>
@endpush
