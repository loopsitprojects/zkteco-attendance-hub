@extends('layouts.app')

@section('title', 'Attendance')
@section('subtitle', number_format($totalRecords).' punches saved from the terminal')

@section('content')
<div class="card">
    <div class="toolbar">
        <input type="search" id="attSearch" class="input input-search" placeholder="Search name, user ID, date, method…" aria-label="Search attendance">

        <div class="segmented" role="group" aria-label="Punch state">
            <button class="active" data-state="ALL" onclick="setAttendanceStateFilter('ALL', this)">All</button>
            <button data-state="CHECK IN" onclick="setAttendanceStateFilter('CHECK IN', this)">Check in</button>
            <button data-state="CHECK OUT" onclick="setAttendanceStateFilter('CHECK OUT', this)">Check out</button>
        </div>

        <select id="empFilter" class="select" aria-label="Filter by employee">
            <option value="">All employees ({{ count($employeeOptions) }})</option>
            @foreach ($employeeOptions as $id => $name)
                <option value="{{ $id }}">{{ $name }} (#{{ $id }})</option>
            @endforeach
        </select>

        <select id="monthFilter" class="select" aria-label="Filter by month">
            <option value="">All months</option>
            @foreach ($monthOptions as $month => $total)
                <option value="{{ $month }}">{{ \Carbon\Carbon::parse($month.'-01')->format('F Y') }} ({{ number_format($total) }})</option>
            @endforeach
        </select>

        <input type="date" id="dateFilter" class="input" aria-label="Filter by date" min="{{ $oldestDate }}" max="{{ $newestDate }}" onchange="onDateChange()">

        <button class="btn btn-ghost" onclick="clearAttendanceFilters()">Clear filters</button>

        <span class="spacer"></span>

        <select id="pageSize" class="select" aria-label="Rows per page" onchange="changePageSize()">
            <option value="25">25 rows</option>
            <option value="50" selected>50 rows</option>
            <option value="100">100 rows</option>
            <option value="250">250 rows</option>
            <option value="500">500 rows</option>
            <option value="all">All rows</option>
        </select>

        <button class="btn btn-primary" id="btnExportFiltered" onclick="exportFilteredCSV()" disabled>Export filtered CSV</button>
    </div>

    <div class="table-wrap" tabindex="0" role="region" aria-label="Attendance records">
        <table>
            <thead>
                <tr>
                    <th class="sortable" onclick="sortAttendance('idx')" style="width: 70px;"># <span id="sort-idx">▲</span></th>
                    <th class="sortable" onclick="sortAttendance('user_id')">User ID <span id="sort-user_id"></span></th>
                    <th class="sortable" onclick="sortAttendance('name')">Employee <span id="sort-name"></span></th>
                    <th class="sortable" onclick="sortAttendance('status')">State <span id="sort-status"></span></th>
                    <th class="sortable" onclick="sortAttendance('method')">Method <span id="sort-method"></span></th>
                    <th class="sortable" onclick="sortAttendance('timestamp')">Date &amp; time <span id="sort-timestamp"></span></th>
                </tr>
            </thead>
            <tbody id="attBody">
                <tr><td colspan="6" class="empty">Loading attendance…</td></tr>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <div id="attCountInfo"></div>
        <div class="pages" id="attPagination"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    let rawAttendance = [];
    let filteredAttendance = [];
    let attStateFilter = 'ALL';
    let attSortCol = 'idx';
    let attSortAsc = true;
    let attPage = 1;
    let attPageSize = 50;

    document.addEventListener('DOMContentLoaded', async () => {
        initSearchableFilters();
        document.getElementById('attSearch').addEventListener('input', filterAttendance);
        try {
            const res = await fetch(@json(route('attendance.data')), { headers: { Accept: 'application/json' } });
            const rows = await res.json();
            rawAttendance = rows.map(r => ({ idx: r[0], user_id: r[1], name: r[2], status: r[3], method: r[4], timestamp: r[5] }));
            filteredAttendance = [...rawAttendance];
            renderAttendance();
        } catch (err) {
            document.getElementById('attBody').innerHTML = `<tr><td colspan="6" class="empty">Could not load attendance: ${escapeHtml(err.message)}</td></tr>`;
        }
    });

    function setAttendanceStateFilter(state, btn) {
        attStateFilter = state;
        document.querySelectorAll('.segmented button').forEach(c => c.classList.toggle('active', c === btn));
        filterAttendance();
    }

    // Select2 search on the employee and month filters. Without the library they stay plain dropdowns.
    function initSearchableFilters() {
        const emp = document.getElementById('empFilter');
        const month = document.getElementById('monthFilter');
        if (!window.jQuery || !jQuery.fn.select2) {
            emp.addEventListener('change', filterAttendance);
            month.addEventListener('change', onMonthChange);
            return;
        }
        // Handlers go through jQuery because Select2 only fires jQuery events.
        jQuery(emp).select2({ width: '260px', dropdownAutoWidth: true }).on('change', filterAttendance);
        jQuery(month).select2({ width: '200px', dropdownAutoWidth: true }).on('change', onMonthChange);
    }

    // Sets a filter's value and keeps a Select2 display in step with it
    function setFilterValue(id, value) {
        const el = document.getElementById(id);
        el.value = value;
        if (window.jQuery && jQuery(el).data('select2')) {
            jQuery(el).trigger('change.select2');
        }
    }

    // A date and a month can't both apply, so choosing one clears the other
    function onMonthChange() {
        document.getElementById('dateFilter').value = '';
        filterAttendance();
    }

    function onDateChange() {
        setFilterValue('monthFilter', '');
        filterAttendance();
    }

    function clearAttendanceFilters() {
        ['attSearch', 'empFilter', 'monthFilter', 'dateFilter'].forEach(id => setFilterValue(id, ''));
        setAttendanceStateFilter('ALL', document.querySelector('.segmented button[data-state="ALL"]'));
    }

    function filterAttendance() {
        const query = (document.getElementById('attSearch').value || '').toLowerCase().trim();
        const empId = document.getElementById('empFilter').value;
        const month = document.getElementById('monthFilter').value;
        const date = document.getElementById('dateFilter').value;

        filteredAttendance = rawAttendance.filter(item => {
            if (attStateFilter !== 'ALL' && item.status !== attStateFilter) return false;
            if (empId && item.user_id !== empId) return false;
            // Timestamps are "YYYY-MM-DD HH:MM:SS"
            if (month && !item.timestamp.startsWith(month)) return false;
            if (date && !item.timestamp.startsWith(date)) return false;
            if (query) {
                const haystack = [item.name, item.user_id, item.timestamp, item.status, item.method].join(' ').toLowerCase();
                if (!haystack.includes(query)) return false;
            }
            return true;
        });

        attPage = 1;
        if (document.getElementById('pageSize').value === 'all') {
            attPageSize = Math.max(filteredAttendance.length, 1);
        }
        sortFilteredAttendance();
        renderAttendance();
    }

    function sortAttendance(col) {
        attSortAsc = attSortCol === col ? !attSortAsc : true;
        attSortCol = col;
        ['idx', 'user_id', 'name', 'status', 'method', 'timestamp'].forEach(c => {
            const el = document.getElementById('sort-' + c);
            if (el) el.textContent = c === attSortCol ? (attSortAsc ? '▲' : '▼') : '';
        });
        sortFilteredAttendance();
        renderAttendance();
    }

    function sortFilteredAttendance() {
        const col = attSortCol;
        const numeric = col === 'idx' || col === 'user_id';
        filteredAttendance.sort((a, b) => {
            let vA = a[col], vB = b[col];
            if (numeric) {
                vA = parseInt(vA, 10) || 0;
                vB = parseInt(vB, 10) || 0;
            } else {
                vA = String(vA || '').toLowerCase();
                vB = String(vB || '').toLowerCase();
            }
            if (vA < vB) return attSortAsc ? -1 : 1;
            if (vA > vB) return attSortAsc ? 1 : -1;
            return 0;
        });
    }

    function changePageSize() {
        const val = document.getElementById('pageSize').value;
        attPageSize = val === 'all' ? Math.max(filteredAttendance.length, 1) : parseInt(val, 10);
        attPage = 1;
        renderAttendance();
    }

    function renderAttendance() {
        const tbody = document.getElementById('attBody');
        const exportBtn = document.getElementById('btnExportFiltered');
        exportBtn.textContent = `Export filtered CSV (${filteredAttendance.length.toLocaleString()})`;
        exportBtn.disabled = filteredAttendance.length === 0;

        const total = filteredAttendance.length;
        if (total === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty">No attendance records match these filters.</td></tr>';
            document.getElementById('attCountInfo').textContent = 'Showing 0 records';
            document.getElementById('attPagination').innerHTML = '';
            return;
        }

        const start = (attPage - 1) * attPageSize;
        const end = Math.min(start + attPageSize, total);
        tbody.innerHTML = filteredAttendance.slice(start, end).map(item => `
            <tr>
                <td class="muted-num">${item.idx}</td>
                <td><strong>${escapeHtml(item.user_id)}</strong></td>
                <td>${personCell(item.name)}</td>
                <td>${stateBadge(item.status)}</td>
                <td>${methodLabel(item.method)}</td>
                <td><code>${escapeHtml(item.timestamp)}</code></td>
            </tr>`).join('');

        document.getElementById('attCountInfo').textContent =
            `Showing ${(start + 1).toLocaleString()}–${end.toLocaleString()} of ${total.toLocaleString()} matching records (${rawAttendance.length.toLocaleString()} in database)`;

        const totalPages = Math.ceil(total / attPageSize);
        const pag = document.getElementById('attPagination');
        if (totalPages <= 1) {
            pag.innerHTML = '';
            return;
        }
        let startPage = Math.max(1, attPage - 2);
        const endPage = Math.min(totalPages, startPage + 4);
        startPage = Math.max(1, endPage - 4);
        let html = `<button class="page-btn" ${attPage === 1 ? 'disabled' : ''} onclick="goToAttPage(${attPage - 1})">‹ Prev</button>`;
        for (let p = startPage; p <= endPage; p++) {
            html += `<button class="page-btn ${p === attPage ? 'active' : ''}" onclick="goToAttPage(${p})">${p}</button>`;
        }
        html += `<button class="page-btn" ${attPage === totalPages ? 'disabled' : ''} onclick="goToAttPage(${attPage + 1})">Next ›</button>`;
        pag.innerHTML = html;
    }

    function goToAttPage(page) {
        attPage = page;
        renderAttendance();
        document.querySelector('.table-wrap').scrollTop = 0;
    }

    function exportFilteredCSV() {
        // Quotes every cell, and stops a spreadsheet treating text as a formula
        const cell = value => {
            const text = String(value ?? '');
            return `"${(/^[=+\-@]/.test(text) ? "'" + text : text).replace(/"/g, '""')}"`;
        };
        const rows = [['No', 'User_ID', 'Employee_Name', 'Punch_State', 'Method', 'Timestamp']];
        filteredAttendance.forEach((item, i) => rows.push([i + 1, item.user_id, item.name, item.status, item.method || 'Unknown', item.timestamp]));
        const csv = rows.map(row => row.map(cell).join(',')).join('\n') + '\n';

        // The filename records which filters produced the file
        const parts = ['attendance'];
        const month = document.getElementById('monthFilter').value;
        const date = document.getElementById('dateFilter').value;
        const empId = document.getElementById('empFilter').value;
        if (month) parts.push(month);
        if (date) parts.push(date);
        if (empId) parts.push('employee-' + empId.replace(/[^0-9a-zA-Z_-]/g, ''));
        if (attStateFilter !== 'ALL') parts.push(attStateFilter.toLowerCase().replace(' ', '-'));
        if (document.getElementById('attSearch').value.trim()) parts.push('search');

        // The leading byte-order mark makes Excel read names as UTF-8
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = parts.join('_') + '.csv';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
</script>
@endpush
