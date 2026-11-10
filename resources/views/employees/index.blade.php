@extends('layouts.app')

@section('title', 'Employees')
@section('subtitle', count($employees).' people enrolled on the terminal')

@section('content')
<div class="card">
    <div class="toolbar">
        <input type="search" id="empSearch" class="input input-search" placeholder="Search name, user ID, role, department…" aria-label="Search employees">
        <div class="segmented" role="group" aria-label="Role">
            <button class="active" onclick="setEmployeeRoleFilter('ALL', this)">All ({{ count($employees) }})</button>
            <button onclick="setEmployeeRoleFilter('Admin', this)">Admins ({{ $adminCount }})</button>
            <button onclick="setEmployeeRoleFilter('User', this)">Standard users ({{ count($employees) - $adminCount }})</button>
        </div>
        <span class="spacer"></span>
        <span class="text-muted" id="empCountInfo"></span>
    </div>

    <div class="table-wrap" tabindex="0" role="region" aria-label="Employee directory">
        <table>
            <thead>
                <tr>
                    <th style="width: 70px;">UID</th>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Role on terminal</th>
                    <th>Department</th>
                    <th>Card</th>
                </tr>
            </thead>
            <tbody id="empBody"></tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const rawEmployees = @json($employees);
    let empRoleFilter = 'ALL';
    let filteredEmployees = [...rawEmployees];

    document.getElementById('empSearch').addEventListener('input', filterEmployees);
    renderEmployees();

    function setEmployeeRoleFilter(role, btn) {
        empRoleFilter = role;
        document.querySelectorAll('.segmented button').forEach(c => c.classList.toggle('active', c === btn));
        filterEmployees();
    }

    function filterEmployees() {
        const query = (document.getElementById('empSearch').value || '').toLowerCase().trim();
        filteredEmployees = rawEmployees.filter(u => {
            if (empRoleFilter === 'Admin' && u.role !== 'Admin') return false;
            if (empRoleFilter === 'User' && u.role === 'Admin') return false;
            if (query && ![u.name, u.user_id, u.role, u.group].join(' ').toLowerCase().includes(query)) return false;
            return true;
        });
        renderEmployees();
    }

    function renderEmployees() {
        const tbody = document.getElementById('empBody');
        document.getElementById('empCountInfo').textContent = `Showing ${filteredEmployees.length} of ${rawEmployees.length}`;
        if (filteredEmployees.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty">${rawEmployees.length ? 'No employees match the search.' : 'No employees saved yet. Run a sync from the terminal.'}</td></tr>`;
            return;
        }
        tbody.innerHTML = filteredEmployees.map(u => `
            <tr>
                <td class="muted-num">#${u.uid}</td>
                <td><strong>${escapeHtml(u.user_id)}</strong></td>
                <td>${personCell(u.name)}</td>
                <td>${u.role === 'Admin' ? '<span class="badge badge-admin">Administrator</span>' : `<span class="badge badge-user">${u.role === 'User' ? 'Standard user' : escapeHtml(u.role)}</span>`}</td>
                <td>${u.group ? `<span class="badge badge-neutral">Dept ${escapeHtml(u.group)}</span>` : '<span class="text-muted">—</span>'}</td>
                <td>${u.card && u.card !== '0' ? `<code>${escapeHtml(u.card)}</code>` : '<span class="text-muted">None</span>'}</td>
            </tr>`).join('');
    }
</script>
@endpush
