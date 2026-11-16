<?php

namespace Tests\Feature;

use App\Services\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The terminal pushing punches to this server (Cloud Server / ADMS). */
class PushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Terminal::class)->saveSettings(['ip' => '192.168.1.5', 'port' => 4370, 'mode' => 'push', 'push_serials' => 'TESTSN1']);
    }

    private function push(string $table, string $body, string $sn = 'TESTSN1')
    {
        return $this->call('POST', "/iclock/cdata?SN={$sn}&table={$table}&Stamp=1", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_unknown_terminals_are_refused(): void
    {
        $this->get('/iclock/cdata?SN=INTRUDER&options=all')->assertForbidden();
        $this->push('ATTLOG', "1\t2026-11-16 08:00:00\t0\t1", 'INTRUDER')->assertForbidden();
        $this->assertSame(0, DB::table('attendance_logs')->count());
    }

    public function test_handshake_tells_the_terminal_to_send_everything_in_real_time(): void
    {
        $this->get('/iclock/cdata?SN=TESTSN1&options=all&pushver=2.4.1')
            ->assertOk()
            ->assertSee('GET OPTION FROM: TESTSN1')
            ->assertSee('ATTLOGStamp=None')
            ->assertSee('Realtime=1');
    }

    public function test_users_and_punches_are_saved_with_check_in_and_out(): void
    {
        $this->push('OPERLOG', "USER PIN=5501\tName=Sakina\tPri=0\tPasswd=\tCard=[0000000000]\tGrp=1\tTZ=0\nOPLOG 4\t0\t2026-11-16 07:00:00\t0\t0\t0\t0")
            ->assertOk()->assertSee('OK');
        $this->assertSame('Sakina', DB::table('employees')->where('user_id', '5501')->value('name'));

        // Sent out of order, plus a duplicate and a broken line
        $this->push('ATTLOG', "5501\t2026-11-16 17:05:00\t0\t1\t0\t0\n5501\t2026-11-16 08:01:00\t0\t1\t0\t0\n5501\t2026-11-16 08:01:00\t0\t1\t0\t0\ngarbage")
            ->assertOk()->assertSee('OK: 3');

        $rows = DB::table('attendance_logs')->orderBy('punch_time')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['CHECK IN', 'CHECK OUT'], $rows->pluck('punch_state')->all());
        $this->assertSame('Sakina', $rows[0]->employee_name);
        $this->assertSame('Fingerprint', $rows[0]->verify_type);
        $this->assertSame('push', $rows[0]->source);

        // A late earlier punch becomes the Check In; the old first one becomes a Check Out
        $this->push('ATTLOG', "5501\t2026-11-16 07:45:00\t0\t1\t0\t0")->assertOk();
        $states = DB::table('attendance_logs')->orderBy('punch_time')->pluck('punch_state')->all();
        $this->assertSame(['CHECK IN', 'CHECK OUT', 'CHECK OUT'], $states);
    }

    public function test_people_go_to_https_but_the_terminal_stays_on_http(): void
    {
        config(['app.url' => 'https://attendance.example.com']);

        $this->get('http://attendance.example.com/login')->assertRedirect('https://attendance.example.com/login');
        $this->get('http://attendance.example.com/iclock/cdata?SN=TESTSN1&options=all')->assertOk();
    }

    public function test_check_ins_mark_the_terminal_online(): void
    {
        $this->get('/iclock/getrequest?SN=TESTSN1&INFO=Ver%206.60,53,106,29185,192.168.1.77')->assertOk()->assertSee('OK');

        $state = DB::table('device_state')->pluck('value', 'key');
        $this->assertSame('192.168.1.77', $state['push_ip']);
        $this->assertSame(29185, json_decode($state['sizes'], true)['records']);
        $this->assertTrue(app(\App\Services\DeviceSync::class)->status()['connected']);
    }
}
