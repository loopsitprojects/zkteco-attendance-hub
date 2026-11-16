<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The site served from a sub-folder (https://example.com/attendance), as on cPanel. */
class SubfolderTest extends TestCase
{
    use RefreshDatabase;

    /** A request as Apache passes it when /attendance links to the app's public folder. */
    private function sub(string $method, string $path, bool $https = true, string $scriptPrefix = '/attendance')
    {
        $server = [
            'HTTP_HOST' => 'uat.example.com',
            'HTTPS' => $https ? 'on' : 'off',
            'SERVER_PORT' => $https ? 443 : 80,
            'SCRIPT_NAME' => "{$scriptPrefix}/index.php",
            'SCRIPT_FILENAME' => public_path('index.php'),
            'PHP_SELF' => "{$scriptPrefix}/index.php",
            'REQUEST_URI' => $path,
        ];
        // The same adjustment public/index.php makes
        $server = (require base_path('bootstrap/subfolder.php'))($server);
        $request = \Illuminate\Http\Request::create(($https ? 'https' : 'http')."://uat.example.com{$path}", $method, [], [], [], $server);
        $request->server->replace(array_merge($request->server->all(), $server));

        return $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
    }

    public function test_pages_and_links_include_the_sub_folder(): void
    {
        config(['app.url' => 'https://uat.example.com/attendance']);

        $response = $this->sub('GET', '/attendance/login');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('action="https://uat.example.com/attendance/login"', $response->getContent());
        $this->assertStringContainsString('https://uat.example.com/attendance/css/app.css', $response->getContent());

        $this->assertSame('https://uat.example.com/attendance/login', $this->sub('GET', '/attendance/')->headers->get('Location'));
    }

    /** Code left in ~/site/attendance; its root .htaccess passes requests to public/index.php. */
    public function test_works_with_the_code_inside_the_website_folder(): void
    {
        config(['app.url' => 'https://uat.example.com/attendance']);
        $via = '/attendance/public';

        $login = $this->sub('GET', '/attendance/login', scriptPrefix: $via);
        $this->assertSame(200, $login->getStatusCode());
        $this->assertStringContainsString('action="https://uat.example.com/attendance/login"', $login->getContent());
        $this->assertStringContainsString('https://uat.example.com/attendance/css/app.css', $login->getContent());

        $this->assertSame('https://uat.example.com/attendance/login', $this->sub('GET', '/attendance/', scriptPrefix: $via)->headers->get('Location'));

        // The domain root's .htaccess sends the terminal to attendance/public/index.php
        app(Terminal::class)->saveSettings(['ip' => '192.168.1.5', 'port' => 4370, 'mode' => 'push', 'push_serials' => 'TESTSN1']);
        $iclock = $this->sub('GET', '/iclock/cdata?SN=TESTSN1&options=all', https: false, scriptPrefix: $via);
        $this->assertSame(200, $iclock->getStatusCode());
        $this->assertStringContainsString('GET OPTION FROM: TESTSN1', $iclock->getContent());
    }

    public function test_subfolder_fix_changes_nothing_in_a_normal_setup(): void
    {
        $fix = require base_path('bootstrap/subfolder.php');
        $normal = ['SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'REQUEST_URI' => '/login'];
        $this->assertSame($normal, $fix($normal));

        $fixed = $fix(['SCRIPT_NAME' => '/attendance/public/index.php', 'PHP_SELF' => '/attendance/public/index.php', 'REQUEST_URI' => '/attendance/login?x=1']);
        $this->assertSame('/attendance/index.php', $fixed['SCRIPT_NAME']);
    }

    public function test_http_redirects_to_https_without_doubling_the_sub_folder(): void
    {
        config(['app.url' => 'https://uat.example.com/attendance']);

        $response = $this->sub('GET', '/attendance/login', https: false);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://uat.example.com/attendance/login', $response->headers->get('Location'));
    }

    public function test_signed_in_users_visiting_login_go_to_the_dashboard(): void
    {
        config(['app.url' => 'https://uat.example.com/attendance']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->assertSame('https://uat.example.com/attendance', $this->sub('GET', '/attendance/login')->headers->get('Location'));
    }

    public function test_terminal_reaches_iclock_at_the_domain_root(): void
    {
        // The terminal always calls /iclock/... at the root; the root .htaccess hands it to the app
        app(Terminal::class)->saveSettings(['ip' => '192.168.1.5', 'port' => 4370, 'mode' => 'push', 'push_serials' => 'TESTSN1']);
        config(['app.url' => 'https://uat.example.com/attendance']);

        $response = $this->sub('GET', '/iclock/cdata?SN=TESTSN1&options=all', https: false);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('GET OPTION FROM: TESTSN1', $response->getContent());
    }
}
