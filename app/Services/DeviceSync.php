<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Downloads everything from the terminal. Saves everything, or nothing if any
 * download is incomplete, so a flaky network never leaves partial data behind.
 */
class DeviceSync
{
    public function __construct(private Terminal $terminal, private AttendanceStore $store) {}

    /** @return array{ok: bool, error?: string, employees?: int, logs?: int} */
    public function run(): array
    {
        if ($this->terminal->isPush()) {
            return ['ok' => false, 'error' => 'push'];
        }

        // A full log download can take minutes on a slow link.
        set_time_limit(300);

        $zk = $this->terminal->connect();
        if (! $zk) {
            return ['ok' => false, 'error' => 'offline'];
        }

        $users = $zk->getUser();
        $complete = $zk->transferComplete && count($users) > 0;
        $logs = [];
        if ($complete) {
            $logs = $zk->getAttendance(0);
            $complete = $zk->transferComplete;
        }
        if (! $complete) {
            $zk->disconnect();

            return ['ok' => false, 'error' => 'incomplete'];
        }

        $this->store->refreshDevice($zk, true);
        $this->store->saveDeviceState(['last_sync' => now()->format('Y-m-d H:i:s')]);
        Terminal::close($zk);

        DB::transaction(function () use ($users, $logs) {
            $this->store->saveEmployees($users);
            $this->store->saveLogs($logs, $this->store->employeeNames());
        });
        Cache::forget('device-status');

        return ['ok' => true, 'employees' => count($users), 'logs' => count($logs)];
    }

    /**
     * Connects briefly to refresh storage figures and read the clock. Cached for a few
     * seconds so several open pages don't each open a session on the terminal.
     */
    public function status(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('device-status');
        }

        // In push mode the terminal calls us; it is online if it checked in recently.
        if ($this->terminal->isPush()) {
            $lastSeen = (int) ($this->store->deviceState()['push_last_seen'] ?? 0);

            return ['connected' => time() - $lastSeen < 120, 'protocol' => 'PUSH', 'deviceTime' => null, 'mode' => 'push'];
        }

        return Cache::remember('device-status', 20, function () {
            $settings = $this->terminal->settings();
            $zk = $this->terminal->connect($settings);
            if (! $zk) {
                return ['connected' => false, 'protocol' => strtoupper($settings['protocol']), 'deviceTime' => null];
            }

            $this->store->refreshDevice($zk);
            $deviceTime = $zk->getTime() ?: null;

            // First run: fill the employee table. After that, Sync keeps it current.
            if (DB::table('employees')->count() === 0) {
                $users = $zk->getUser();
                if ($zk->transferComplete && count($users) > 0) {
                    DB::transaction(fn () => $this->store->saveEmployees($users));
                }
            }
            $protocol = strtoupper($zk->protocol);
            Terminal::close($zk);

            return ['connected' => true, 'protocol' => $protocol, 'deviceTime' => $deviceTime];
        });
    }
}
