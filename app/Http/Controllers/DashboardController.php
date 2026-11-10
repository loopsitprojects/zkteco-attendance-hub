<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(AttendanceStore $store)
    {
        $state = $store->deviceState();
        $today = now()->toDateString();

        $employees = DB::table('employees')->where('on_device', true);
        $todayLogs = DB::table('attendance_logs')->whereDate('punch_time', $today);

        return view('dashboard.index', [
            'state' => $state,
            'sizes' => json_decode($state['sizes'] ?? 'null', true),
            'employeeCount' => (clone $employees)->count(),
            'adminCount' => (clone $employees)->where('role', 14)->count(),
            'totalRecords' => DB::table('attendance_logs')->count(),
            'todayPunches' => (clone $todayLogs)->count(),
            'todayPeople' => (clone $todayLogs)->distinct()->count('user_id'),
            'todayCheckIns' => (clone $todayLogs)->where('punch_state', 'CHECK IN')->count(),
        ]);
    }
}
