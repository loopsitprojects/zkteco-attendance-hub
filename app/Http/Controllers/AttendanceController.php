<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index()
    {
        // Filter choices, including people who have punches but are no longer on the terminal
        $employeeOptions = [];
        foreach (DB::table('employees')->where('on_device', true)->get(['user_id', 'name']) as $r) {
            $employeeOptions[(string) $r->user_id] = $r->name ?: 'No name on device';
        }
        $withPunches = DB::table('attendance_logs as a')
            ->leftJoin('employees as e', 'e.user_id', '=', 'a.user_id')
            ->selectRaw("a.user_id, MAX(COALESCE(NULLIF(e.name, ''), a.employee_name)) AS name")
            ->groupBy('a.user_id')->get();
        foreach ($withPunches as $r) {
            $employeeOptions[(string) $r->user_id] ??= $r->name ?: 'No name on device';
        }
        uasort($employeeOptions, 'strcasecmp');

        $months = DB::table('attendance_logs')
            ->selectRaw("DATE_FORMAT(punch_time, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')->orderByDesc('month')
            ->pluck('total', 'month')->all();

        $range = DB::table('attendance_logs')->selectRaw('DATE(MIN(punch_time)) AS oldest, DATE(MAX(punch_time)) AS newest')->first();

        return view('attendance.index', [
            'employeeOptions' => $employeeOptions,
            'monthOptions' => $months,
            'oldestDate' => $range->oldest,
            'newestDate' => $range->newest,
            'totalRecords' => array_sum($months),
        ]);
    }

    /** All punches, newest first, as compact rows for the in-browser filters. */
    public function data()
    {
        $rows = $this->query()->get()->map(fn ($r, $i) => [
            $i + 1,
            (string) $r->user_id,
            mb_scrub((string) ($r->employee_name ?: 'No name on device'), 'UTF-8'),
            (string) $r->punch_state,
            (string) ($r->verify_type ?: 'Unknown'),
            (string) $r->punch_time,
        ]);

        return response()->json($rows, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function export(): StreamedResponse
    {
        $filename = 'zkteco_attendance_export_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () {
            $fp = fopen('php://output', 'w');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['ID', 'User_ID', 'Employee_Name', 'Punch_State', 'Method', 'Timestamp']);
            foreach ($this->query()->cursor() as $row) {
                fputcsv($fp, array_map([self::class, 'csvSafe'], [
                    $row->id, $row->user_id, $row->employee_name, $row->punch_state, $row->verify_type ?: 'Unknown', $row->punch_time,
                ]));
            }
            fclose($fp);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops a spreadsheet treating a cell as a formula. */
    public static function csvSafe($value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@]/', $text) ? "'".$text : $text;
    }

    private function query()
    {
        return DB::table('attendance_logs as a')
            ->leftJoin('employees as e', 'e.user_id', '=', 'a.user_id')
            ->selectRaw("a.id, a.user_id, COALESCE(NULLIF(e.name, ''), a.employee_name) AS employee_name, a.punch_state, a.verify_type, a.punch_time")
            ->orderByDesc('a.punch_time')->orderByDesc('a.id');
    }
}
