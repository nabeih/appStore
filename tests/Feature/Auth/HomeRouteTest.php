<?php

namespace Tests\Feature\Auth;

use App\Models\ApplicationInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_installation_redirects_root_to_the_public_storefront(): void
    {
        ApplicationInstallation::query()->whereKey(1)->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->get('/')->assertRedirect(route('store.home'));
        $this->get('/')->assertRedirect('/shop');
        $this->get('/shop')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_eligible_first_time_installation_redirects_root_to_setup(): void
    {
        ApplicationInstallation::query()->whereKey(1)->update([
            'status' => 'unprepared',
            'completed_at' => null,
        ]);

        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get('/')->assertRedirect('/setup');
    }

    public function test_invalid_installation_state_returns_service_unavailable(): void
    {
        ApplicationInstallation::query()->whereKey(1)->update([
            'status' => 'blocked',
            'completed_at' => null,
        ]);

        $this->get('/')->assertStatus(503);
    }
}
