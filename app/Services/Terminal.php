<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use ZKLib;

/**
 * Connection settings for the ZKTeco terminal and a factory for connected clients.
 * Settings live in the `settings` table so the website, the listener and the
 * scheduler all use the same values.
 */
class Terminal
{
    public const PROTOCOLS = ['tcp', 'udp', 'auto'];

    public function settings(): array
    {
        $saved = DB::table('settings')->pluck('value', 'key')->all();
        $defaults = config('zkteco');

        return [
            'ip' => (string) ($saved['ip'] ?? $defaults['ip']),
            'port' => (int) ($saved['port'] ?? $defaults['port']),
            'protocol' => (string) ($saved['protocol'] ?? $defaults['protocol']),
            'timeout_sec' => (int) ($saved['timeout_sec'] ?? $defaults['timeout_sec']),
            'comm_key' => (int) ($saved['comm_key'] ?? $defaults['comm_key']),
        ];
    }

    /** Saves new settings. Returns true when the address changed, meaning a different terminal. */
    public function saveSettings(array $values): bool
    {
        $old = $this->settings();
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['key' => $key, 'value' => (string) $value];
        }
        DB::table('settings')->upsert($rows, ['key'], ['value']);

        return $old['ip'] !== (string) $values['ip'] || $old['port'] !== (int) $values['port'];
    }

    public function make(?array $settings = null): ZKLib
    {
        $s = $settings ?? $this->settings();

        return new ZKLib($s['ip'], $s['port'], $s['timeout_sec'], 0, $s['comm_key'], $s['protocol']);
    }

    /** A connected client, or null when the terminal does not answer. */
    public function connect(?array $settings = null): ?ZKLib
    {
        $zk = $this->make($settings);

        return $zk->connect() ? $zk : null;
    }

    public static function close(ZKLib $zk): void
    {
        $zk->enableDevice();
        $zk->disconnect();
    }
}
