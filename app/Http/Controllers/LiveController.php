<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use Illuminate\Support\Facades\DB;

class LiveController extends Controller
{
    /** Punches captured by the listener. Answered from the database, without contacting the terminal. */
    public function feed(AttendanceStore $store)
    {
        $state = $store->deviceState();
        $push = app(\App\Services\Terminal::class)->isPush();
        $active = $push
            ? time() - (int) ($state['push_last_seen'] ?? 0) < 120
            : time() - (int) ($state['listener_heartbeat'] ?? 0) < 30;

        $rows = DB::table('attendance_logs as a')
            ->leftJoin('employees as e', 'e.user_id', '=', 'a.user_id')
            ->whereIn('a.source', ['live', 'push'])
            ->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->limit(20)
            ->selectRaw("a.id, a.user_id, COALESCE(NULLIF(e.name, ''), a.employee_name) AS name,
                a.punch_state AS status, a.verify_type AS method, a.punch_time AS time, a.created_at AS captured_at")
            ->get();

        return response()->json([
            'listener_active' => $active,
            'mode' => $push ? 'push' : 'direct',
            'punches' => $rows,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
