<?php

require_once(__DIR__ . "/zkconst.php");

class ZKLib {
    public $ip;
    public $port;
    public $zkclient;
    public $protocol; // 'auto', 'tcp', or 'udp'
    public $comm_key = 0;
    
    public $data_recv = '';
    public $session_id = 0;
    public $reply_id = 65534;
    public $userdata = array();
    public $attendancedata = array();
    public $timeout_sec = 10;
    public $timeout_usec = 0;
    // True only if the last getUser()/getAttendance() received every byte the device announced.
    public $transferComplete = false;

    public function __construct($ip, $port = 4370, $timeout_sec = 10, $timeout_usec = 0, $comm_key = 0, $protocol = 'auto') {
        $this->ip = $ip;
        $this->port = (int)$port;
        $this->timeout_sec = (int)$timeout_sec;
        $this->timeout_usec = (int)$timeout_usec;
        $this->comm_key = (int)$comm_key;
        $this->protocol = strtolower($protocol);
    }

    public function initSocket() {
        if ($this->zkclient) {
            @socket_close($this->zkclient);
            $this->zkclient = null;
        }

        if ($this->protocol === 'auto') {
            $testSock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            @socket_set_nonblock($testSock);
            @socket_connect($testSock, $this->ip, $this->port);
            $w = [$testSock];
            $r = [$testSock];
            $e = null;
            if (@socket_select($r, $w, $e, 1, 0) > 0 && @socket_get_option($testSock, SOL_SOCKET, SO_ERROR) === 0) {
                @socket_set_block($testSock);
                $this->protocol = 'tcp';
                $this->zkclient = $testSock;
            } else {
                @socket_close($testSock);
                $this->protocol = 'udp';
                $this->zkclient = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            }
        } elseif ($this->protocol === 'tcp') {
            $this->zkclient = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        } else {
            $this->protocol = 'udp';
            $this->zkclient = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        }

        $timeout = array('sec' => $this->timeout_sec, 'usec' => $this->timeout_usec);
        socket_set_option($this->zkclient, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($this->zkclient, SOL_SOCKET, SO_SNDTIMEO, $timeout);
    }

    public function createChkSum($p) {
        $l = count($p);
        $chksum = 0;
        $i = $l;
        $j = 1;
        while ($i > 1) {
            $u = unpack('S', pack('C2', $p['c'.$j], $p['c'.($j+1)] ) );
            $chksum += $u[1];
            if ($chksum > USHRT_MAX) $chksum -= USHRT_MAX;
            $i -= 2;
            $j += 2;
        }
        if ($i) {
            $chksum = $chksum + $p['c'.strval(count($p))];
        }
        while ($chksum > USHRT_MAX) {
            $chksum -= USHRT_MAX;
        }
        if ($chksum > 0) $chksum = -($chksum);
        else $chksum = abs($chksum);
        $chksum -= 1;
        while ($chksum < 0) {
            $chksum += USHRT_MAX;
        }
        return pack('S', $chksum);
    }

    public function createHeader($command, $chksum, $session_id, $reply_id, $command_string) {
        $buf = pack('SSSS', $command, $chksum, $session_id, $reply_id) . $command_string;
        $buf = unpack('C' . (8 + strlen($command_string)) . 'c', $buf);
        $u = unpack('S', $this->createChkSum($buf));
        $chksum = is_array($u) ? reset($u) : $u;
        $reply_id += 1;
        if ($reply_id >= USHRT_MAX) {
            $reply_id -= USHRT_MAX;
        }
        return pack('SSSS', $command, $chksum, $session_id, $reply_id) . $command_string;
    }

    public function makeCommKey($key, $session_id, $ticks = 50) {
        $k = (int)$key;
        for ($i = 0; $i < 32; $i++) {
            if ($k & (1 << $i)) $k = ($k << 1) | 1;
            else $k = $k << 1;
        }
        $k += (int)$session_id;
        $b = pack("I", $k);
        $k0 = ord($b[0]) ^ ord("Z");
        $k1 = ord($b[1]) ^ ord("K");
        $k2 = ord($b[2]) ^ ord("S");
        $k3 = ord($b[3]) ^ ord("O");
        $swapped = pack("vv", $k2 | ($k3 << 8), $k0 | ($k1 << 8));
        $B = 0xff & $ticks;
        return chr(ord($swapped[0]) ^ $B) . chr(ord($swapped[1]) ^ $B) . chr($B) . chr(ord($swapped[3]) ^ $B);
    }

    public function sendCmd($command, $command_string = '') {
        $buf = $this->createHeader($command, 0, $this->session_id, $this->reply_id, $command_string);
        $this->reply_id++;
        if ($this->reply_id >= USHRT_MAX) {
            $this->reply_id -= USHRT_MAX;
        }

        if ($this->protocol === 'tcp') {
            $top = pack('vvV', 20560, 32130, strlen($buf)) . $buf;
            return @socket_write($this->zkclient, $top, strlen($top));
        } else {
            return @socket_sendto($this->zkclient, $buf, strlen($buf), 0, $this->ip, $this->port);
        }
    }

    public function recvResp($maxBytes = 65536) {
        $this->data_recv = '';
        if ($this->protocol === 'tcp') {
            $raw = @socket_read($this->zkclient, $maxBytes);
            if ($raw === false || strlen($raw) < 8) {
                return false;
            }
            $this->data_recv = substr($raw, 8);
        } else {
            @socket_recvfrom($this->zkclient, $this->data_recv, $maxBytes, 0, $this->ip, $this->port);
        }

        if (strlen($this->data_recv) >= 8) {
            $zkHead = unpack('vcmd/vchk/vsession/vreply', substr($this->data_recv, 0, 8));
            $this->session_id = $zkHead['session'];
            $this->reply_id = $zkHead['reply'];
        }
        return $this->data_recv;
    }

    public function connect() {
        $this->initSocket();

        if ($this->protocol === 'tcp') {
            if (!@socket_getpeername($this->zkclient, $peer)) {
                @socket_set_nonblock($this->zkclient);
                $res = @socket_connect($this->zkclient, $this->ip, $this->port);
                if (!$res) {
                    $write = [$this->zkclient];
                    $read = [$this->zkclient];
                    $except = null;
                    $sec = $this->timeout_sec > 0 ? $this->timeout_sec : 5;
                    $usec = $this->timeout_usec;
                    if (@socket_select($read, $write, $except, $sec, $usec) > 0) {
                        $so_error = socket_get_option($this->zkclient, SOL_SOCKET, SO_ERROR);
                        if ($so_error !== 0) {
                            return false;
                        }
                    } else {
                        return false; // Connection timed out
                    }
                }
                @socket_set_block($this->zkclient);
            }
        }

        $this->reply_id = USHRT_MAX - 1;
        $this->session_id = 0;

        $this->sendCmd(CMD_CONNECT, '');
        $resp = $this->recvResp(1024);
        if (!$resp || strlen($resp) < 8) {
            return false;
        }

        $zkHead = unpack('vcmd/vchk/vsession/vreply', substr($resp, 0, 8));
        if ($zkHead['cmd'] === CMD_ACK_UNAUTH) {
            $authPayload = $this->makeCommKey($this->comm_key, $this->session_id);
            $this->sendCmd(CMD_AUTH, $authPayload);
            $resp = $this->recvResp(1024);
            if (!$resp || strlen($resp) < 8) {
                return false;
            }
            $zkHead = unpack('vcmd/vchk/vsession/vreply', substr($resp, 0, 8));
        }

        return ($zkHead['cmd'] === CMD_ACK_OK);
    }

    public function disconnect() {
        $this->sendCmd(CMD_EXIT, '');
        if ($this->zkclient) {
            @socket_close($this->zkclient);
            $this->zkclient = null;
        }
        return true;
    }

    public function getDeviceParam($param) {
        $this->sendCmd(CMD_DEVICE, $param . "\0");
        $resp = $this->recvResp(1024);
        if (!$resp || strlen($resp) <= 8) return '';
        $val = substr($resp, 8);
        if (strpos($val, '=') !== false) {
            $parts = explode('=', $val, 2);
            $val = $parts[1];
        }
        return rtrim($val, "\0 \r\n");
    }

    public function version() {
        $this->sendCmd(CMD_VERSION, '');
        $resp = $this->recvResp(1024);
        if (!$resp || strlen($resp) <= 8) return '';
        return rtrim(substr($resp, 8), "\0 \r\n");
    }

    public function osversion() {
        return $this->getDeviceParam('~OS');
    }

    public function platform() {
        return $this->getDeviceParam('~Platform');
    }

    public function fmVersion() {
        return $this->getDeviceParam('~ZKFPVersion');
    }

    public function workCode() {
        return $this->getDeviceParam('WorkCode');
    }

    public function ssr() {
        return $this->getDeviceParam('~SSR');
    }

    public function pinWidth() {
        return $this->getDeviceParam('~PIN2Width');
    }

    public function faceFunctionOn() {
        return $this->getDeviceParam('FaceFunOn');
    }

    public function serialNumber() {
        return $this->getDeviceParam('~SerialNumber');
    }

    public function deviceName() {
        return $this->getDeviceParam('~DeviceName');
    }

    public function disableDevice() {
        $this->sendCmd(CMD_DISABLEDEVICE, chr(0) . chr(0));
        $this->recvResp(1024);
        return true;
    }

    public function enableDevice() {
        $this->sendCmd(CMD_ENABLEDEVICE, '');
        $this->recvResp(1024);
        return true;
    }

    public function testVoice($index = 0) {
        $this->sendCmd(CMD_TESTVOICE, pack('I', (int)$index));
        $this->recvResp(1024);
        return true;
    }

    public function getTime() {
        $this->sendCmd(CMD_GET_TIME, '');
        $resp = $this->recvResp(1024);
        if (!$resp || strlen($resp) < 12) return false;
        $raw = substr($resp, 8, 4);
        $timeInt = unpack('V', $raw)[1];
        return decode_time($timeInt);
    }

    public function setTime($t) {
        $this->sendCmd(CMD_SET_TIME, pack('I', encode_time($t)));
        $this->recvResp(1024);
        return true;
    }

    public function getUser() {
        $this->transferComplete = false;
        $this->sendCmd(CMD_USERTEMP_RRQ, chr(5));

        if ($this->protocol === 'tcp') {
            $userData = substr($this->readTcpData(), 4);
        } else {
            // UDP handling
            $resp = $this->recvResp(1024);
            if (!$resp || strlen($resp) < 8) return [];
            $cmd = unpack('v', substr($resp, 0, 2))[1];
            if ($cmd !== CMD_PREPARE_DATA) return [];

            $allData = $this->readUdpChunks(unpack('V', substr($resp, 8, 4))[1]);
            $userData = implode('', $allData);
            $userData = substr($userData, 11);
        }

        $users = [];
        while (strlen($userData) >= 72) {
            $chunk = substr($userData, 0, 72);
            $uid = ord($chunk[0]) + (ord($chunk[1]) * 256);
            $role = ord($chunk[2]);
            $password = explode("\0", substr($chunk, 3, 8))[0];
            $name = explode("\0", substr($chunk, 11, 24))[0];
            $userid = explode("\0", substr($chunk, 48, 24))[0];
            $cardInt = unpack('V', substr($chunk, 35, 4))[1];
            $cardno = $cardInt ? (string)$cardInt : '';
            $group = explode("\0", substr($chunk, 40, 7))[0];

            if ($userid !== '' || $name !== '') {
                $users[$uid] = [$userid, $name, $cardno, $uid, $role, $password, $group];
            }
            $userData = substr($userData, 72);
        }
        return $users;
    }

    public function readExact($bytes) {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = @socket_read($this->zkclient, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') return false;
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * Reads a framed TCP data transfer. Sets $transferComplete only when the bytes
     * received match the size the device announced. Stops early at $maxBytes if given.
     */
    private function readTcpData($maxBytes = 0) {
        $data = '';
        $expected = null;
        while (true) {
            $head = $this->readExact(8);
            if (!$head) return $data;
            $top = unpack('vmagic1/vmagic2/Vlen', $head);
            if ($top['magic1'] !== 20560 || $top['magic2'] !== 32130) return $data;

            $body = $this->readExact($top['len']);
            if (!$body) return $data;

            $cmd = unpack('v', substr($body, 0, 2))[1];
            if ($cmd === CMD_PREPARE_DATA) {
                $expected = unpack('V', substr($body, 8, 4))[1];
            } elseif ($cmd === CMD_DATA) {
                $data .= substr($body, 8);
                if ($expected === null) { // Small transfer sent without an announced size
                    $this->transferComplete = true;
                    return $data;
                }
                if ($maxBytes > 0 && strlen($data) >= $maxBytes) {
                    return $data;
                }
            } elseif ($cmd === CMD_ACK_OK) {
                $this->transferComplete = ($expected === null || strlen($data) === $expected);
                return $data;
            }
        }
    }

    /** Reads an announced UDP transfer, stopping at the first timeout instead of waiting on every chunk. */
    private function readUdpChunks($totalSize) {
        $bytes = $totalSize;
        $allData = [];
        while ($bytes > 0) {
            $chunk = '';
            if (@socket_recvfrom($this->zkclient, $chunk, 1032, 0, $this->ip, $this->port) === false) {
                return $allData;
            }
            $allData[] = (count($allData) > 0) ? substr($chunk, 8) : $chunk;
            $bytes -= 1024;
        }
        @socket_recvfrom($this->zkclient, $dummy, 1024, 0, $this->ip, $this->port);
        $this->transferComplete = true;
        return $allData;
    }

    public function getAttendance($maxRecords = 0) {
        $this->transferComplete = false;
        $this->sendCmd(CMD_ATTLOG_RRQ, '');

        if ($this->protocol === 'tcp') {
            $attData = substr($this->readTcpData($maxRecords > 0 ? ($maxRecords * 40) + 4 : 0), 4);
        } else {
            // UDP handling
            $resp = $this->recvResp(1024);
            if (!$resp || strlen($resp) < 8) return [];
            $cmd = unpack('v', substr($resp, 0, 2))[1];
            if ($cmd !== CMD_PREPARE_DATA) return [];

            $allData = $this->readUdpChunks(unpack('V', substr($resp, 8, 4))[1]);
            $attData = implode('', $allData);
            $attData = substr($attData, 10);
        }

        $attendance = [];
        $idx = 0;
        while (strlen($attData) >= 40) {
            $chunk = substr($attData, 0, 40);
            $attData = substr($attData, 40);

            $uid = ord($chunk[0]) + (ord($chunk[1]) * 256);
            $userid = explode("\0", substr($chunk, 2, 24))[0];
            $status = ord($chunk[26]);
            $timeInt = unpack('V', substr($chunk, 27, 4))[1];
            $timestamp = decode_time($timeInt);

            if ($userid !== '' && preg_match('/^[0-9a-zA-Z_-]+$/', $userid)) {
                $attendance[$idx++] = [$uid, $userid, $status, $timestamp];
                if ($maxRecords > 0 && $idx >= $maxRecords) {
                    break;
                }
            }
        }
        return $attendance;
    }

    public function clearAttendance() {
        $this->sendCmd(CMD_CLEAR_ATTLOG, '');
        $this->recvResp(1024);
        return true;
    }

    public function clearUser() {
        $this->sendCmd(CMD_CLEAR_DATA, '');
        $this->recvResp(1024);
        return true;
    }

    public function clearAdmin() {
        $this->sendCmd(CMD_CLEAR_ADMIN, '');
        $this->recvResp(1024);
        return true;
    }

    public function restart() {
        $this->sendCmd(CMD_RESTART, '');
        $this->recvResp(1024);
        return true;
    }

    public function powerOff() {
        $this->sendCmd(CMD_POWEROFF, '');
        $this->recvResp(1024);
        return true;
    }

    public function enrollUser($userid) {
        $this->sendCmd(CMD_STARTENROLL, pack("a*", $userid));
        $this->recvResp(1024);
        return true;
    }

    public function getSizes() {
        $this->sendCmd(CMD_GET_FREE_SIZES, "");
        $resp = $this->recvResp(1024);
        if (!$resp || strlen($resp) < 88) return null;
        $payload = substr($resp, 8);
        $fields = unpack("i20", substr($payload, 0, 80));
        return [
            'users' => $fields[5] ?? 0,
            'fingers' => $fields[7] ?? 0,
            'records' => $fields[9] ?? 0,
            'cards' => $fields[13] ?? 0,
            'fingers_cap' => $fields[15] ?? 0,
            'users_cap' => $fields[16] ?? 0,
            'records_cap' => $fields[17] ?? 0,
            'records_avail' => $fields[20] ?? (($fields[17] ?? 0) - ($fields[9] ?? 0)),
        ];
    }
}
