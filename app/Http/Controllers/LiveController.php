<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use Illuminate\Support\Facades\DB;

class LiveController extends Controller
{
    /** Punches captured by the listener. Answered from the database, without contacting the terminal. */
    public function feed(AttendanceStore $store)
    {
        $heartbeat = (int) ($store->deviceState()['listener_heartbeat'] ?? 0);

        $rows = DB::table('attendance_logs as a')
            ->leftJoin('employees as e', 'e.user_id', '=', 'a.user_id')
            ->where('a.source', 'live')
            ->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->limit(20)
            ->selectRaw("a.id, a.user_id, COALESCE(NULLIF(e.name, ''), a.employee_name) AS name,
                a.punch_state AS status, a.verify_type AS method, a.punch_time AS time, a.created_at AS captured_at")
            ->get();

        return response()->json([
            'listener_active' => (time() - $heartbeat) < 30,
            'punches' => $rows,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
