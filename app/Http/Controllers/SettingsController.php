<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use App\Services\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function edit(Terminal $terminal)
    {
        return view('settings.index', ['settings' => $terminal->settings()]);
    }

    public function update(Request $request, Terminal $terminal, AttendanceStore $store)
    {
        $values = $this->validated($request);

        // Saved device details describe the old terminal if the address changed
        if ($terminal->saveSettings($values)) {
            $store->clearDeviceState();
        }
        Cache::forget('device-status');

        return redirect()->route('settings')->with('success', 'Connection settings saved. The website, listener and scheduled sync now use them.');
    }

    /** Tries the values typed in the form, before they are saved. */
    public function test(Request $request, Terminal $terminal)
    {
        $s = $this->validated($request);
        $s['timeout_sec'] = min($s['timeout_sec'], 30);

        $t0 = microtime(true);
        $zk = $terminal->connect($s);
        $latencyMs = (int) round((microtime(true) - $t0) * 1000);

        if (! $zk) {
            return response()->json([
                'success' => false,
                'message' => "Connection failed to {$s['ip']}:{$s['port']} via ".strtoupper($s['protocol'])." (timeout {$s['timeout_sec']}s). Check the IP address, network, port or firewall.",
                'latency_ms' => $latencyMs,
            ]);
        }

        $result = [
            'success' => true,
            'latency_ms' => $latencyMs,
            'protocol' => strtoupper($zk->protocol),
            'deviceName' => $zk->deviceName() ?: 'ZKTeco terminal',
            'serialNumber' => $zk->serialNumber() ?: 'Unknown',
            'version' => $zk->version() ?: 'Unknown',
            'deviceTime' => $zk->getTime() ?: 'Unknown',
        ];
        $result['message'] = "Connected to {$result['deviceName']} via {$result['protocol']}.";
        Terminal::close($zk);

        return response()->json($result);
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            // Only addresses on the local network: the terminal is never on the internet.
            'ip' => ['required', 'ip', function ($attr, $value, $fail) {
                if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) !== false) {
                    $fail('Use the terminal\'s local network address, such as 192.168.1.5.');
                }
            }],
            'port' => ['required', 'integer', 'between:1,65535'],
            'protocol' => ['required', Rule::in(Terminal::PROTOCOLS)],
            'timeout_sec' => ['required', 'integer', 'between:1,60'],
            'comm_key' => ['nullable', 'integer', 'min:0'],
        ]);
        $v['comm_key'] = (int) ($v['comm_key'] ?? 0);
        $v['port'] = (int) $v['port'];
        $v['timeout_sec'] = (int) $v['timeout_sec'];

        return $v;
    }
}
