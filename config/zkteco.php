<?php

// Defaults used until connection settings are saved from the Settings page.
return [
    'ip' => env('ZK_IP', '192.168.1.5'),
    'port' => (int) env('ZK_PORT', 4370),
    'protocol' => env('ZK_PROTOCOL', 'tcp'),
    'timeout_sec' => (int) env('ZK_TIMEOUT', 10),
    'comm_key' => (int) env('ZK_COMM_KEY', 0),
];
