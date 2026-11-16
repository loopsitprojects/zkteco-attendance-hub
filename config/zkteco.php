<?php

// Defaults used until connection settings are saved from the Settings page.
return [
    'ip' => env('ZK_IP', '192.168.1.5'),
    'port' => (int) env('ZK_PORT', 4370),
    'protocol' => env('ZK_PROTOCOL', 'tcp'),
    'timeout_sec' => (int) env('ZK_TIMEOUT', 10),
    'comm_key' => (int) env('ZK_COMM_KEY', 0),

    // direct: this server connects to the terminal on the local network.
    // push: the terminal sends punches to this server over the internet (Cloud Server / ADMS).
    'mode' => env('ZK_MODE', 'direct'),
    // Comma-separated serial numbers allowed to push punches (shown on the terminal under System Info).
    'push_serials' => env('ZK_PUSH_SERIALS', ''),
];
