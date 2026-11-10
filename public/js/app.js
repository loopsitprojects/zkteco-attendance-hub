// Shared helpers for every page.

function escapeHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function initials(name) {
    return String(name || '').split(/\s+/).filter(Boolean).map(n => n[0]).join('').substring(0, 2).toUpperCase() || '#';
}

function personCell(name) {
    return `<span class="person"><span class="avatar">${escapeHtml(initials(name))}</span>${escapeHtml(name)}</span>`;
}

function stateBadge(state) {
    return `<span class="badge ${state === 'CHECK IN' ? 'badge-in' : 'badge-out'}">${state === 'CHECK IN' ? 'Check in' : 'Check out'}</span>`;
}

function methodLabel(method) {
    return method === 'Fingerprint'
        ? '<span class="badge badge-neutral">👆 Fingerprint</span>'
        : `<span class="text-muted">${escapeHtml(method || 'Unknown')}</span>`;
}

function toggleSidebar(open) {
    document.body.classList.toggle('nav-open', open);
}

/** POST with the CSRF token; returns the decoded JSON. */
async function postJson(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': App.csrf },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0][0] : null;
        throw new Error(first || data.message || `Request failed (${res.status})`);
    }
    return data;
}

// ---------- Terminal status in the top bar ----------
const DeviceStatus = {
    clockBase: null,   // terminal time at the last reading, in ms
    readAt: 0,
    checked: false,   // false until the first status check answers

    async refresh(fresh = false) {
        try {
            const res = await fetch(App.routes.status + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const s = await res.json();
            const chip = document.getElementById('deviceChip');
            chip.className = 'status-chip ' + (s.connected ? 'online' : 'offline');
            chip.querySelector('.dot').className = 'dot ' + (s.connected ? 'dot-online' : 'dot-offline');
            const name = s.deviceName ? ` · ${s.deviceName}` : '';
            document.getElementById('deviceChipText').textContent = s.connected ? `Online (${s.protocol})${name}` : `Disconnected${name}`;

            if (s.connected && s.deviceTime) {
                this.clockBase = new Date(s.deviceTime.replace(' ', 'T')).getTime();
                this.readAt = Date.now();
            } else {
                this.clockBase = null;
            }
            this.checked = true;
            this.tick();
            document.dispatchEvent(new CustomEvent('device-status', { detail: s }));
        } catch (e) { /* the next poll will try again */ }
    },

    tick() {
        const el = document.getElementById('deviceClock');
        if (!el || !this.checked) return;
        if (this.clockBase === null) { el.textContent = 'Not connected'; return; }
        const d = new Date(this.clockBase + (Date.now() - this.readAt));
        const p = n => String(n).padStart(2, '0');
        el.textContent = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
    },
};

document.addEventListener('DOMContentLoaded', () => {
    DeviceStatus.refresh();
    setInterval(() => DeviceStatus.refresh(), 30000);
    setInterval(() => DeviceStatus.tick(), 1000);
});
