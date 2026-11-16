<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use ZKLib;

/**
 * Storage shared by the website, the real-time listener and scheduled sync.
 * Everything saved here was read from the terminal; missing values stay missing.
 */
class AttendanceStore
{
    public const VERIFY_LABELS = [1 => 'Fingerprint', 2 => 'Password', 3 => 'Card'];

    public function deviceState(): array
    {
        return DB::table('device_state')->pluck('value', 'key')->all();
    }

    public function saveDeviceState(array $values): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['key' => $key, 'value' => (string) $value, 'updated_at' => $now];
        }
        if ($rows) {
            DB::table('device_state')->upsert($rows, ['key'], ['value', 'updated_at']);
        }
    }

    public function clearDeviceState(): void
    {
        DB::table('device_state')->delete();
    }

    /**
     * Saves what the connected terminal reports. A value it fails to return leaves the
     * previously saved one in place. Identity fields are only re-read when $full is true
     * or when none are saved yet, since each one is a network round trip.
     */
    public function refreshDevice(ZKLib $zk, bool $full = false): array
    {
        $values = ['last_seen' => now()->format('Y-m-d H:i:s')];

        if ($full || empty($this->deviceState()['serialNumber'])) {
            $identity = [
                'deviceName' => $zk->deviceName(),
                'serialNumber' => $zk->serialNumber(),
                'version' => $zk->version(),
                'osversion' => $zk->osversion(),
                'platform' => $zk->platform(),
                'fmVersion' => $zk->fmVersion(),
                'workCode' => $zk->workCode(),
                'ssr' => $zk->ssr(),
                'pinWidth' => $zk->pinWidth(),
                'faceFunction' => $zk->faceFunctionOn(),
            ];
            $values += array_filter($identity, fn ($v) => $v !== '' && $v !== false && $v !== null);
        }

        $sizes = $zk->getSizes();
        if ($sizes) {
            $values['sizes'] = json_encode($sizes);
            $values['sizes_at'] = $values['last_seen'];
        }

        $this->saveDeviceState($values);

        return $this->deviceState();
    }

    /**
     * Stores the terminal's complete user list. Pass only a complete download: anyone
     * missing from it is marked as no longer on the device. Their saved name is kept so
     * their old punches stay labelled. Passwords are deliberately not stored.
     */
    public function saveEmployees(array $users): void
    {
        DB::table('employees')->update(['on_device' => false]);

        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($users as $uid => $u) {
            $rows[] = [
                (string) $u[0],
                (int) $uid,
                trim(mb_scrub((string) $u[1], 'UTF-8')),
                (int) $u[4],
                (string) $u[2],
                (string) ($u[6] ?? ''),
                1,
                $now,
            ];
        }

        $this->bulkUpsert('employees',
            ['user_id', 'device_uid', 'name', 'role', 'card', 'group_name', 'on_device', 'updated_at'],
            $rows,
            "device_uid = VALUES(device_uid),
             name = COALESCE(NULLIF(VALUES(name), ''), employees.name),
             role = VALUES(role), card = VALUES(card), group_name = VALUES(group_name),
             on_device = 1, updated_at = VALUES(updated_at)");
    }

    public function employeeNames(): array
    {
        return DB::table('employees')->whereNotNull('name')->where('name', '!=', '')->pluck('name', 'user_id')->all();
    }

    public static function verifyLabel($code): string
    {
        return self::VERIFY_LABELS[(int) $code] ?? 'Unknown';
    }

    /**
     * Saves downloaded logs, pairing each person's punches per day into Check In / Check Out
     * when the terminal doesn't record a state. Returns the saved rows. Caller owns the transaction.
     */
    public function saveLogs(array $logs, array $names): array
    {
        $byUserDate = [];
        foreach ($logs as $l) {
            $byUserDate[(string) $l[1]][substr($l[3], 0, 10)][] = $l;
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        $saved = [];
        foreach ($byUserDate as $userId => $dates) {
            $userId = (string) $userId;
            $name = $names[$userId] ?? null;
            foreach ($dates as $punches) {
                usort($punches, fn ($a, $b) => strcmp($a[3], $b[3]));
                foreach ($punches as $seq => $p) {
                    $rawPunch = isset($p[4]) ? (int) $p[4] : 255;
                    $state = match (true) {
                        $rawPunch === 0 => 'CHECK IN',
                        $rawPunch === 1 => 'CHECK OUT',
                        default => $seq === 0 ? 'CHECK IN' : 'CHECK OUT',
                    };
                    $method = self::verifyLabel($p[2]);
                    $rows[] = [$userId, $name, $state, $method, (int) $p[2], $rawPunch, $p[3], 'device', $now];
                    $saved[] = ['user_id' => $userId, 'name' => $name, 'state' => $state, 'method' => $method, 'time' => $p[3]];
                }
            }
        }

        $this->bulkUpsert('attendance_logs',
            ['user_id', 'employee_name', 'punch_state', 'verify_type', 'raw_status', 'raw_punch', 'punch_time', 'source', 'created_at'],
            $rows,
            self::LOG_UPDATES);

        return $saved;
    }

    /** Labels a live punch: the person's first punch of the day is a Check In. */
    public function liveState(string $userId, string $time, int $punch): string
    {
        if ($punch === 0) {
            return 'CHECK IN';
        }
        if ($punch === 1) {
            return 'CHECK OUT';
        }
        $hasPunched = DB::table('attendance_logs')
            ->where('user_id', $userId)
            ->whereDate('punch_time', substr($time, 0, 10))
            ->where('punch_time', '<', $time)
            ->exists();

        return $hasPunched ? 'CHECK OUT' : 'CHECK IN';
    }

    /** Inserts or updates one punch and returns its row id, which stays stable across re-syncs. */
    public function savePunch(string $userId, ?string $name, string $state, string $method, int $status, int $punch, string $time, string $source): int
    {
        $this->bulkUpsert('attendance_logs',
            ['user_id', 'employee_name', 'punch_state', 'verify_type', 'raw_status', 'raw_punch', 'punch_time', 'source', 'created_at'],
            [[$userId, $name, $state, $method, $status, $punch, $time, $source, now()->format('Y-m-d H:i:s')]],
            self::LOG_UPDATES);

        return (int) DB::table('attendance_logs')->where('user_id', $userId)->where('punch_time', $time)->value('id');
    }

    /** Verify codes in the push (ADMS) protocol differ from the SDK's. */
    public const PUSH_VERIFY_LABELS = [0 => 'Password', 1 => 'Fingerprint', 2 => 'Card', 3 => 'Password', 4 => 'Card', 15 => 'Face'];

    /**
     * Saves punches the terminal pushed: [user id, time, raw state, verify code].
     * Each affected person-day is then re-labelled, so punches arriving late or out
     * of order still get the right Check In / Check Out. Returns how many were saved.
     */
    public function savePushedPunches(array $punches): int
    {
        if (! $punches) {
            return 0;
        }
        $names = $this->employeeNames();
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        $days = [];
        foreach ($punches as [$userId, $time, $state, $verify]) {
            $rows[] = [$userId, $names[$userId] ?? null, 'CHECK IN', self::PUSH_VERIFY_LABELS[$verify] ?? 'Unknown', $verify, $state, $time, 'push', $now];
            $days[$userId][substr($time, 0, 10)] = true;
        }

        DB::transaction(function () use ($rows, $days) {
            // punch_state is left alone on re-sends; relabelDays sets it below
            $this->bulkUpsert('attendance_logs',
                ['user_id', 'employee_name', 'punch_state', 'verify_type', 'raw_status', 'raw_punch', 'punch_time', 'source', 'created_at'],
                $rows,
                'employee_name = COALESCE(VALUES(employee_name), attendance_logs.employee_name),
                 verify_type = VALUES(verify_type), raw_status = VALUES(raw_status), raw_punch = VALUES(raw_punch)');
            $this->relabelDays($days);
        });

        return count($rows);
    }

    /** First punch of each listed person-day is a Check In, the rest are Check Outs. */
    public function relabelDays(array $days): void
    {
        foreach ($days as $userId => $dates) {
            foreach (array_keys($dates) as $date) {
                $ids = DB::table('attendance_logs')
                    ->where('user_id', (string) $userId)
                    ->whereBetween('punch_time', ["{$date} 00:00:00", "{$date} 23:59:59"])
                    ->orderBy('punch_time')->pluck('id');
                if ($ids->isEmpty()) {
                    continue;
                }
                DB::table('attendance_logs')->where('id', $ids->first())->update(['punch_state' => 'CHECK IN']);
                DB::table('attendance_logs')->whereIn('id', $ids->slice(1)->all())->update(['punch_state' => 'CHECK OUT']);
            }
        }
    }

    /** Adds or updates one person the terminal reported, without touching anyone else. */
    public function saveEmployee(string $userId, ?string $name, int $role, ?string $card, ?string $group): void
    {
        $this->bulkUpsert('employees',
            ['user_id', 'name', 'role', 'card', 'group_name', 'on_device', 'updated_at'],
            [[$userId, trim(mb_scrub((string) $name, 'UTF-8')), $role, (string) $card, (string) $group, 1, now()->format('Y-m-d H:i:s')]],
            "name = COALESCE(NULLIF(VALUES(name), ''), employees.name),
             role = VALUES(role), card = VALUES(card), group_name = VALUES(group_name),
             on_device = 1, updated_at = VALUES(updated_at)");
        DB::table('attendance_logs')->where('user_id', $userId)->whereNull('employee_name')
            ->update(['employee_name' => DB::raw('(SELECT name FROM employees WHERE employees.user_id = attendance_logs.user_id)')]);
    }

    private const LOG_UPDATES ="employee_name = COALESCE(VALUES(employee_name), attendance_logs.employee_name),
        punch_state = VALUES(punch_state), verify_type = VALUES(verify_type),
        raw_status = VALUES(raw_status), raw_punch = VALUES(raw_punch)";

    /** INSERT ... ON DUPLICATE KEY UPDATE in chunks; VALUES(col) is the incoming value (works on MySQL and MariaDB). */
    private function bulkUpsert(string $table, array $columns, array $rows, string $updates): void
    {
        $placeholder = '('.implode(',', array_fill(0, count($columns), '?')).')';
        foreach (array_chunk($rows, 500) as $chunk) {
            $sql = "INSERT INTO {$table} (".implode(',', $columns).') VALUES '
                .implode(',', array_fill(0, count($chunk), $placeholder))
                ." ON DUPLICATE KEY UPDATE {$updates}";
            DB::insert($sql, array_merge(...$chunk));
        }
    }
}
