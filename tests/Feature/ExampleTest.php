<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/attendance/export')->assertRedirect('/login');
    }

    public function test_viewers_cannot_use_admin_pages(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/settings')->assertForbidden();
        $this->actingAs($viewer)->get('/users')->assertForbidden();
        $this->actingAs($viewer)->postJson('/device/restart')->assertForbidden();
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put("/users/{$admin->id}", ['role' => 'viewer'])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
