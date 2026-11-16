<?php

/*
 * Entry point used only when this project folder itself is served by the web server,
 * for example https://example.com/attendance with the code in ~/example.com/attendance
 * on cPanel. The .htaccess beside this file sends every request here, except files
 * that exist in public/ (CSS, JS, images). Nothing else in this folder is ever served.
 *
 * With a normal setup (document root = public/) this file is never reached.
 */

require __DIR__.'/public/index.php';
