<?php

namespace App\Console\Commands;

use App\Services\AttendanceStore;
use App\Services\Terminal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Subscribes to the terminal's real-time events and saves each punch the moment it happens.
 * Reconnects by itself when the network drops or the terminal reboots. Run it as a service.
 */
class ListenDevice extends Command
{
    protected $signature = 'zk:listen';

    protected $description = 'Capture fingerprint punches from the terminal in real time';

    public function handle(Terminal $terminal, AttendanceStore $store): int
    {
        while (true) {
            $settings = $terminal->settings();
            $this->line("Connecting to {$settings['ip']}:{$settings['port']} (".strtoupper($settings['protocol']).')...');

            try {
                $zk = $terminal->connect($settings);
            } catch (Throwable $e) {
                $zk = null;
                $this->warn('Connection error: '.$e->getMessage());
            }
            if (! $zk) {
                $this->warn('Terminal did not answer. Retrying in 5 seconds...');
                sleep(5);

                continue;
            }

            try {
                $this->listen($zk, $store);
            } catch (Throwable $e) {
                $this->warn('Listener error: '.$e->getMessage().'. Reconnecting in 3 seconds...');
                DB::reconnect();
            }
            if (isset($zk->zkclient) && $zk->zkclient) {
                @socket_close($zk->zkclient);
            }
            sleep(3);
        }
    }

    private function listen($zk, AttendanceStore $store): void
    {
        $this->info('Connected via '.strtoupper($zk->protocol).': '.$zk->deviceName().' (S/N '.$zk->serialNumber().')');

        $users = $zk->getUser();
        if ($zk->transferComplete && count($users) > 0) {
            DB::transaction(fn () => $store->saveEmployees($users));
        } else {
            $this->warn('Employee download incomplete; using the names already saved.');
        }
        $names = $store->employeeNames();
        $store->refreshDevice($zk);
        $this->line('Loaded '.count($names).' employee names.');

        $zk->sendCmd(CMD_CANCELCAPTURE, '');
        $zk->recvResp(1024);
        $zk->sendCmd(CMD_STARTVERIFY, '');
        $zk->recvResp(1024);
        $zk->enableDevice();
        $zk->sendCmd(CMD_REG_EVENT, pack('I', 0xFFFF));
        $zk->recvResp(1024);

        $this->info('LISTENER ACTIVE: waiting for fingerprint scans.');

        socket_set_option($zk->zkclient, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        $lastHeartbeat = 0;

        while (true) {
            // Lets the website report whether this listener is really running
            if (time() - $lastHeartbeat >= 10) {
                $store->saveDeviceState(['listener_heartbeat' => time()]);
                $lastHeartbeat = time();
            }

            $raw = @socket_read($zk->zkclient, 2048);
            if ($raw === '') {
                $this->warn('The terminal closed the connection.');

                return;
            }
            if ($raw === false) {
                $err = socket_last_error($zk->zkclient);
                if ($err !== SOCKET_EAGAIN && $err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    $this->warn("Socket error ({$err}): ".socket_strerror($err));

                    return;
                }

                continue;
            }
            if (strlen($raw) < 16) {
                continue;
            }

            // Acknowledge immediately, or the terminal re-sends the event
            $buf = $zk->createHeader(CMD_ACK_OK, 0, $zk->session_id, USHRT_MAX - 1, '');
            if ($zk->protocol === 'tcp') {
                $top = pack('vvV', 20560, 32130, strlen($buf)).$buf;
                @socket_write($zk->zkclient, $top, strlen($top));
                $header = unpack('vcmd/vchk/vsession/vreply', substr($raw, 8, 8));
                $data = substr($raw, 16);
            } else {
                @socket_sendto($zk->zkclient, $buf, strlen($buf), 0, $zk->ip, $zk->port);
                $header = unpack('vcmd/vchk/vsession/vreply', substr($raw, 0, 8));
                $data = substr($raw, 8);
            }

            if ($header['cmd'] !== CMD_REG_EVENT) {
                continue;
            }

            foreach ($this->parseEvents($data) as [$userId, $status, $punch, $timeHex]) {
                $this->savePunch($store, $names, $userId, $status, $punch, $timeHex);
            }
        }
    }

    /** Splits an event payload into [user id, verify method, punch state, packed time] records. */
    private function parseEvents(string $data): array
    {
        $events = [];
        while (strlen($data) >= 10) {
            $len = strlen($data);
            $userId = '';
            $status = $punch = 0;
            $timeHex = '';

            if (in_array($len, [10, 12, 14], true)) {
                $p = unpack(($len === 12 ? 'V' : 'v').'uid/Cstatus/Cpunch/a6timehex', $len === 12 ? $data : substr($data, 0, 10));
                [$userId, $status, $punch, $timeHex] = [(string) $p['uid'], $p['status'], $p['punch'], $p['timehex']];
                $consumed = $len;
            } elseif ($len >= 32) {
                $userId = explode("\0", substr($data, 0, 24))[0];
                $status = ord($data[24]);
                $punch = ord($data[25]);
                $timeHex = substr($data, 26, 6);
                $consumed = min($len, 52);
            } else {
                $consumed = $len;
            }

            $data = substr($data, $consumed);
            if ($userId !== '') {
                $events[] = [$userId, $status, $punch, $timeHex];
            }
        }

        return $events;
    }

    private function savePunch(AttendanceStore $store, array $names, string $userId, int $status, int $punch, string $timeHex): void
    {
        $time = $this->decodeTime($timeHex);
        if ($time === null) {
            $this->warn("Ignored a punch for user {$userId}: no valid time. Sync will pick it up.");

            return;
        }

        $name = $names[$userId] ?? null;
        $method = AttendanceStore::verifyLabel($status);

        try {
            $state = $store->liveState($userId, $time, $punch);
            $store->savePunch($userId, $name, $state, $method, $status, $punch, $time, 'live');
        } catch (Throwable $e) {
            $this->error("Could not save the punch for {$userId}: ".$e->getMessage().' (Sync will pick it up.)');
            DB::reconnect();

            return;
        }

        $this->info(sprintf('[%s] %s  %s (%s)  %s  %s', now()->format('H:i:s'), $state, $name ?? 'No name on device', $userId, $method, $time));
    }

    private function decodeTime(string $hex): ?string
    {
        if (strlen($hex) !== 6) {
            return null;
        }
        $t = unpack('C6', $hex);
        $time = sprintf('%04d-%02d-%02d %02d:%02d:%02d', 2000 + $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]);

        return checkdate($t[2], $t[3], 2000 + $t[1]) && $t[4] < 24 && $t[5] < 60 && $t[6] < 60 ? $time : null;
    }
}
