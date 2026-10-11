<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_all_dashboards(): void
    {
        $this->get(route('patient.dashboard'))->assertRedirect(route('login'));
        $this->get(route('doctor.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_each_role_can_access_only_its_dashboard(): void
    {
        foreach (['patient', 'doctor', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $dashboard = route("{$role}.dashboard");

            $this->actingAs($user)->get($dashboard)->assertOk();

            foreach (['patient', 'doctor', 'admin'] as $otherRole) {
                if ($otherRole !== $role) {
                    $this->actingAs($user)
                        ->get(route("{$otherRole}.dashboard"))
                        ->assertForbidden();
                }
            }
        }
    }
}
