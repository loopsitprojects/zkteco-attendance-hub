<?php

namespace App\Http\Controllers;

use App\Services\AttendanceStore;
use App\Services\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives punches pushed by the terminal (ZKTeco "Cloud Server" / ADMS / iclock protocol).
 * The terminal connects out to this server, so no port forwarding is needed at the office.
 * Only terminals whose serial number is listed in the Connection settings are accepted.
 */
class AdmsController extends Controller
{
    public function __construct(private Terminal $terminal, private AttendanceStore $store) {}

    /** Handshake: the terminal asks how to upload. */
    public function handshake(Request $request)
    {
        $sn = $this->authorise($request);
        $this->seen($sn, $request);

        return $this->text(implode("\n", [
            "GET OPTION FROM: {$sn}",
            'ATTLOGStamp=None',        // upload every punch not yet sent
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=30',
            'Delay=10',                // check in every 10 seconds
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog OpLog EnrollUser ChgUser',
            'Realtime=1',              // send each punch the moment it happens
            'Encrypt=None',
        ]));
    }

    /** Data upload: punches (ATTLOG) and user details (OPERLOG / USERINFO). */
    public function upload(Request $request)
    {
        $sn = $this->authorise($request);
        $this->seen($sn, $request);
        $lines = preg_split('/\r\n|\n|\r/', trim((string) $request->getContent())) ?: [];
        $table = strtoupper((string) $request->query('table'));

        if ($table === 'ATTLOG') {
            $punches = [];
            foreach ($lines as $line) {
                $f = explode("\t", $line);
                $userId = trim($f[0] ?? '');
                $time = trim($f[1] ?? '');
                if ($userId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time) || strtotime($time) === false) {
                    continue;
                }
                $punches[] = [$userId, $time, (int) ($f[2] ?? 255), (int) ($f[3] ?? 1)];
            }
            $saved = $this->store->savePushedPunches($punches);

            return $this->text("OK: {$saved}");
        }

        if (in_array($table, ['OPERLOG', 'USERINFO'], true)) {
            $count = 0;
            foreach ($lines as $line) {
                // "USER PIN=5501<TAB>Name=Sakina<TAB>Pri=0<TAB>Card=..." (USERINFO has no "USER " prefix)
                if ($table === 'OPERLOG' && ! str_starts_with($line, 'USER ')) {
                    continue;
                }
                $user = $this->fields(preg_replace('/^USER /', '', $line));
                if (($user['PIN'] ?? '') === '') {
                    continue;
                }
                $card = trim($user['Card'] ?? '', '[] ');
                $this->store->saveEmployee(
                    $user['PIN'],
                    $user['Name'] ?? null,
                    (int) ($user['Pri'] ?? 0),
                    $card === '' || trim($card, '0') === '' ? '0' : $card,
                    $user['Grp'] ?? null,
                );
                $count++;
            }

            return $this->text('OK: '.max($count, count($lines)));
        }

        // Fingerprint templates, photos and other tables are acknowledged but not stored.
        return $this->text('OK: '.count($lines));
    }

    /** The terminal checks in every few seconds and asks for commands. */
    public function poll(Request $request)
    {
        $sn = $this->authorise($request);
        $this->seen($sn, $request);

        return $this->text('OK');
    }

    /** Results of commands; nothing is queued yet, so they are just acknowledged. */
    public function commandResult(Request $request)
    {
        $this->seen($this->authorise($request), $request);

        return $this->text('OK');
    }

    private function authorise(Request $request): string
    {
        $sn = trim((string) $request->query('SN'));
        if ($sn === '' || ! in_array($sn, $this->terminal->pushSerials(), true)) {
            Log::warning('Push from an unknown terminal was refused', ['sn' => $sn, 'ip' => $request->ip()]);
            abort(response('Unknown device', 403, ['Content-Type' => 'text/plain']));
        }

        return $sn;
    }

    /** Records that the terminal is online, plus what it reports about itself. */
    private function seen(string $sn, Request $request): void
    {
        // Saving on every 10-second check-in is wasteful; once a minute is plenty.
        if (! Cache::add("adms-seen:{$sn}", 1, 60) && ! $request->has('INFO')) {
            return;
        }
        $values = ['push_last_seen' => time(), 'serialNumber' => $sn];

        // INFO=firmware,users,fingerprints,punches,ip,...
        if ($info = $request->query('INFO')) {
            $p = explode(',', (string) $info);
            if (isset($p[0]) && $p[0] !== '') {
                $values['version'] = $p[0];
            }
            if (isset($p[4]) && filter_var($p[4], FILTER_VALIDATE_IP)) {
                $values['push_ip'] = $p[4];
            }
            $sizes = json_decode($this->store->deviceState()['sizes'] ?? '{}', true) ?: [];
            foreach (['users' => 1, 'fingers' => 2, 'records' => 3] as $key => $i) {
                if (isset($p[$i]) && is_numeric($p[$i])) {
                    $sizes[$key] = (int) $p[$i];
                }
            }
            $values['sizes'] = json_encode($sizes);
            $values['sizes_at'] = now()->format('Y-m-d H:i:s');
        }
        $values['last_seen'] = now()->format('Y-m-d H:i:s');
        $this->store->saveDeviceState($values);
    }

    private function fields(string $line): array
    {
        $out = [];
        foreach (explode("\t", $line) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[trim($k)] = trim($v);
        }

        return $out;
    }

    private function text(string $body)
    {
        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
