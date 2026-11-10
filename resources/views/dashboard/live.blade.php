<div class="card">
    <div class="card-header">
        <div>
            <h2>Live punches</h2>
            <p>Saved by the real-time listener the moment someone scans. Refreshes every 2 seconds.</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <span class="badge badge-idle" id="liveStatusPill">Checking listener…</span>
            <button class="btn btn-secondary btn-sm" onclick="clearLiveFeed()">Clear view</button>
        </div>
    </div>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Live punches">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>User ID</th>
                    <th>Employee</th>
                    <th>State</th>
                    <th>Method</th>
                    <th>Punch time</th>
                    <th>Captured at</th>
                </tr>
            </thead>
            <tbody id="realtimeBody">
                <tr><td colspan="7" class="empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
    let liveClearedAt = null;

    async function fetchLiveFeed() {
        try {
            const res = await fetch(App.routes.live, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const feed = await res.json();
            const data = feed.punches;
            if (!Array.isArray(data)) return;

            const pill = document.getElementById('liveStatusPill');
            pill.className = 'badge ' + (feed.listener_active ? 'badge-live' : 'badge-idle');
            pill.textContent = feed.listener_active ? '● Listener active' : '○ Listener not running';

            const tbody = document.getElementById('realtimeBody');
            const shown = liveClearedAt ? data.filter(p => p.captured_at > liveClearedAt) : data;
            if (shown.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="empty">${liveClearedAt
                    ? 'View cleared. New scans will appear here.'
                    : feed.listener_active
                        ? 'Waiting for finger scans…'
                        : 'No live punches saved yet. Start the listener (php artisan zk:listen) to capture them.'}</td></tr>`;
                return;
            }
            tbody.innerHTML = shown.map((item, i) => `
                <tr>
                    <td class="muted-num">${i + 1}</td>
                    <td><strong>${escapeHtml(item.user_id)}</strong></td>
                    <td>${personCell(item.name || 'No name on device')}</td>
                    <td>${stateBadge(item.status)}</td>
                    <td>${methodLabel(item.method)}</td>
                    <td><code>${escapeHtml(item.time)}</code></td>
                    <td class="text-muted">${escapeHtml(item.captured_at)}</td>
                </tr>`).join('');
        } catch (err) { /* try again on the next tick */ }
    }

    // Hides what is on screen now; scans captured afterwards still appear.
    function clearLiveFeed() {
        liveClearedAt = new Date();
        const p = n => String(n).padStart(2, '0');
        const d = liveClearedAt;
        liveClearedAt = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
        fetchLiveFeed();
    }

    fetchLiveFeed();
    setInterval(fetchLiveFeed, 2000);
</script>
@endpush
