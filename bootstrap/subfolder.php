<?php

/**
 * Lets the app run from a sub-folder of a website without moving the code, e.g.
 * https://example.com/attendance served from ~/example.com/attendance on cPanel.
 *
 * The project's root .htaccess passes each request to public/index.php, so PHP reports
 * the script as /attendance/public/index.php while the address is /attendance/login.
 * Laravel then can't tell that /attendance is the app's base, and every route 404s.
 * Reporting the script as /attendance/index.php fixes that. Nothing changes when the
 * site is served normally (document root = public/) or by `php artisan serve`.
 */
return function (array $server): array {
    $script = $server['SCRIPT_NAME'] ?? '';
    $uri = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

    if (str_ends_with($script, '/public/index.php') && ! str_contains($uri, '/public/')) {
        $base = substr($script, 0, -strlen('/public/index.php'));
        $server['SCRIPT_NAME'] = $base.'/index.php';
        $server['PHP_SELF'] = $base.'/index.php';
    }

    return $server;
};
