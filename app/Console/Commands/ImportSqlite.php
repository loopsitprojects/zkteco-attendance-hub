<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/** One-time move of the old attendance.db (and config.json) into MySQL. Safe to run again. */
class ImportSqlite extends Command
{
    protected $signature = 'zk:import-sqlite {path? : Path to attendance.db} {--config= : Path to config.json}';

    protected $description = 'Import employees, punches and device details from the old SQLite database';

    public function handle(): int
    {
        $path = $this->argument('path') ?: storage_path('app/legacy/attendance.db');
        if (! is_file($path)) {
            $this->error("No database found at {$path}");

            return self::FAILURE;
        }

        $sqlite = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        DB::transaction(function () use ($sqlite, $tables) {
            if (in_array('employees', $tables, true)) {
                $rows = $sqlite->query('SELECT * FROM employees')->fetchAll(PDO::FETCH_ASSOC);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('employees')->upsert(array_map(fn ($r) => [
                        'user_id' => (string) $r['user_id'],
                        'device_uid' => $r['device_uid'],
                        'name' => $r['name'],
                        'role' => (int) $r['role'],
                        'card' => $r['card'],
                        'group_name' => $r['group_name'],
                        'on_device' => (bool) $r['on_device'],
                        'updated_at' => $this->localTime($r['updated_at']),
                    ], $chunk), ['user_id']);
                }
                $this->info('Employees: '.count($rows));
            }

            if (in_array('device_state', $tables, true)) {
                $rows = $sqlite->query('SELECT * FROM device_state')->fetchAll(PDO::FETCH_ASSOC);
                DB::table('device_state')->upsert(array_map(fn ($r) => [
                    'key' => $r['key'], 'value' => $r['value'], 'updated_at' => $this->localTime($r['updated_at']),
                ], $rows), ['key']);
                $this->info('Device details: '.count($rows));
            }

            $count = 0;
            $skipped = 0;
            $stmt = $sqlite->query('SELECT * FROM attendance_logs ORDER BY id');
            $batch = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $r['punch_time']) || ! strtotime($r['punch_time'])) {
                    $skipped++;

                    continue;
                }
                $batch[] = [
                    'user_id' => (string) $r['user_id'],
                    'employee_name' => $r['employee_name'],
                    'punch_state' => $r['punch_state'],
                    'verify_type' => $r['verify_type'],
                    'raw_status' => $r['raw_status'],
                    'raw_punch' => $r['raw_punch'],
                    'punch_time' => $r['punch_time'],
                    'source' => $r['source'] ?? 'device',
                    'created_at' => $this->localTime($r['created_at']),
                ];
                if (count($batch) === 1000) {
                    $count += $this->insertLogs($batch);
                    $batch = [];
                }
            }
            $count += $this->insertLogs($batch);
            $this->info("Punches: {$count}".($skipped ? " ({$skipped} skipped: invalid time)" : ''));
        });

        $configPath = $this->option('config') ?: dirname($path).'/config.json';
        if (is_file($configPath) && ($cfg = json_decode(file_get_contents($configPath), true))) {
            $keys = ['ip', 'port', 'protocol', 'timeout_sec', 'comm_key'];
            DB::table('settings')->upsert(
                collect($keys)->filter(fn ($k) => isset($cfg[$k]))->map(fn ($k) => ['key' => $k, 'value' => (string) $cfg[$k]])->values()->all(),
                ['key'], ['value']
            );
            $this->info("Connection settings imported from {$configPath}");
        }

        return self::SUCCESS;
    }

    private function insertLogs(array $batch): int
    {
        if ($batch) {
            DB::table('attendance_logs')->upsert($batch, ['user_id', 'punch_time'],
                ['employee_name', 'punch_state', 'verify_type', 'raw_status', 'raw_punch', 'source', 'created_at']);
        }

        return count($batch);
    }

    /** SQLite's CURRENT_TIMESTAMP is UTC; MySQL stores the office's local time. */
    private function localTime(?string $utc): ?string
    {
        return $utc ? Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
    }
}
