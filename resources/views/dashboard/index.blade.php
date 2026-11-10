@extends('layouts.app')

@section('title', 'Overview')
@section('subtitle', now()->format('l, j F Y'))

@section('content')
@php
    $recUsed = isset($sizes['records']) ? (int) $sizes['records'] : null;
    $recCap = isset($sizes['records_cap']) ? (int) $sizes['records_cap'] : null;
    $recPct = $recCap ? round($recUsed / $recCap * 100, 1) : null;
    $count = fn ($v) => $v === null ? '—' : number_format((int) $v);
@endphp

<div class="stats">
    <div class="card stat">
        <div class="label">Terminal</div>
        <div class="value" id="statTerminal"><span class="text-muted">Checking…</span></div>
        <div class="sub">{{ $state['deviceName'] ?? '—' }} · S/N {{ $state['serialNumber'] ?? '—' }}</div>
    </div>

    <div class="card stat">
        <div class="label">Today</div>
        <div class="value">{{ number_format($todayPeople) }} <small>people</small></div>
        <div class="sub">{{ number_format($todayPunches) }} punches · {{ number_format($todayCheckIns) }} check-ins</div>
    </div>

    <div class="card stat">
        <div class="label">Employees on terminal</div>
        <div class="value">{{ number_format($employeeCount) }} <small>/ {{ $count($sizes['users_cap'] ?? null) }} capacity</small></div>
        <div class="sub">{{ $adminCount }} admins · {{ $employeeCount - $adminCount }} standard users</div>
    </div>

    <div class="card stat" id="memoryCard">
        <div class="label">Terminal memory</div>
        @if ($recPct === null)
            <div class="value">—</div>
            <div class="sub">Not read from the terminal yet</div>
        @else
            @php $tone = $recPct > 90 ? 'danger' : ($recPct > 75 ? 'warning' : 'success'); @endphp
            <div class="value text-{{ $tone }}">{{ $recPct }}% <small>used</small></div>
            <div class="meter"><span style="width: {{ min(100, $recPct) }}%; background: var(--{{ $tone }});"></span></div>
            <div class="sub">
                {{ number_format($recUsed) }} / {{ number_format($recCap) }} logs
                @if ($recPct >= 90)<br><strong class="text-danger">Only {{ number_format($recCap - $recUsed) }} slots left. Clear the terminal's logs after a sync.</strong>@endif
            </div>
            <div class="sub">Read {{ $state['sizes_at'] ?? '—' }}</div>
        @endif
    </div>

    <div class="card stat">
        <div class="label">Punches in database</div>
        <div class="value text-primary" style="color: var(--primary);">{{ number_format($totalRecords) }}</div>
        <div class="sub">Last sync: {{ $state['last_sync'] ?? 'Never' }}</div>
    </div>
</div>

@include('dashboard.live')
@endsection

@push('scripts')
<script>
    document.addEventListener('device-status', e => {
        const s = e.detail;
        document.getElementById('statTerminal').innerHTML = s.connected
            ? `<span class="text-success">Online</span> <small>${escapeHtml(s.protocol)}</small>`
            : '<span class="text-danger">Disconnected</span>';
    });
</script>
@endpush
