<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LocalToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_access_is_unavailable_outside_local_environment(): void
    {
        $this->get('/local-access/'.str_repeat('a', 64))->assertNotFound();
        $this->artisan('asgard:local-access', ['user' => User::factory()->create()->id])->assertFailed();
    }

    public function test_local_access_is_one_time_and_requires_loopback(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $token = str_repeat('a', 64);
        $user = User::factory()->create();
        Cache::put('asgard.local-access.'.hash('sha256', $token), $user->id, now()->addMinutes(5));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->get('http://localhost/local-access/'.$token)->assertNotFound();
        $this->assertGuest();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://localhost/local-access/'.$token)->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('http://localhost/local-access/'.$token)->assertNotFound();
    }
}
