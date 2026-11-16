<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use App\Services\DeviceSync;
use App\Services\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function index(AttendanceStore $store)
    {
        $state = $store->deviceState();

        return view('device.index', [
            'state' => $state,
            'sizes' => json_decode($state['sizes'] ?? 'null', true),
            'employeeCount' => DB::table('employees')->where('on_device', true)->count(),
        ]);
    }

    /** Live terminal status for the top bar. */
    public function status(Request $request, DeviceSync $sync, AttendanceStore $store)
    {
        $status = $sync->status($request->boolean('fresh'));
        $state = $store->deviceState();

        return response()->json($status + [
            'deviceName' => $state['deviceName'] ?? null,
            'serialNumber' => $state['serialNumber'] ?? null,
            'sizes' => json_decode($state['sizes'] ?? 'null', true),
            'sizesAt' => $state['sizes_at'] ?? null,
            'lastSync' => $state['last_sync'] ?? null,
            'serverTime' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function sync(DeviceSync $sync)
    {
        $result = $sync->run();

        if (! $result['ok']) {
            if ($result['error'] === 'push') {
                return back()->with('success', 'Push mode is on: the terminal sends every punch to this server by itself, so there is nothing to download.');
            }
            $reason = $result['error'] === 'offline' ? 'the terminal did not answer.' : 'the download from the terminal was incomplete.';

            return back()->with('error', "Sync failed and nothing was saved: {$reason} Your existing records are unchanged. Please try again.");
        }

        return back()->with('success', sprintf(
            'Sync complete. %s employees and %s punches checked and saved to the database.',
            number_format($result['employees']), number_format($result['logs'])
        ));
    }

    public function syncTime(Terminal $terminal)
    {
        return $this->withTerminal($terminal, function ($zk) {
            $serverTime = now()->format('Y-m-d H:i:s');
            $zk->setTime($serverTime);

            return ['success' => true, 'time' => $serverTime];
        });
    }

    public function testVoice(Terminal $terminal)
    {
        return $this->withTerminal($terminal, function ($zk) {
            $zk->testVoice(0);

            return ['success' => true, 'message' => 'Voice test sent. Listen for the prompt on the terminal.'];
        });
    }

    public function restart(Terminal $terminal)
    {
        if ($terminal->isPush()) {
            return response()->json(['success' => false, 'message' => 'Not available in push mode: restart the terminal from its own menu.']);
        }
        $zk = $terminal->connect();
        if (! $zk) {
            return response()->json(['success' => false, 'message' => 'Failed to connect to the terminal.']);
        }
        $zk->restart();
        $zk->disconnect();

        return response()->json(['success' => true, 'message' => 'Restart command sent. The terminal is rebooting.']);
    }

    private function withTerminal(Terminal $terminal, callable $action)
    {
        if ($terminal->isPush()) {
            return response()->json(['success' => false, 'message' => 'Not available in push mode: this server cannot connect to the terminal. Use the terminal\'s own menu.']);
        }
        $zk = $terminal->connect();
        if (! $zk) {
            return response()->json(['success' => false, 'message' => 'Failed to connect to the terminal.']);
        }
        $result = $action($zk);
        Terminal::close($zk);

        return response()->json($result);
    }
}
