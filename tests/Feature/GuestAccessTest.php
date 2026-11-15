<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * myDoc has no guest access. Only sign-in, patient registration, password recovery and invitation acceptance are
 * reachable without a session; every other application page sends guests to the login page.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route names intentionally reachable by guests (they all carry the `guest` middleware).
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        'login', 'register', 'password.request', 'password.email', 'password.reset', 'password.update',
        'doctor.invitation.show', 'doctor.invitation.accept', 'doctor.invitation.activated',
        'assistant.invitation.show', 'assistant.invitation.accept', 'assistant.invitation.activated',
    ];

    /**
     * Framework routes outside the application: health check, signed local-disk file URLs and the local Boost
     * browser-log endpoint.
     *
     * @var list<string>
     */
    private const FRAMEWORK_URIS = ['up', 'storage/{path}', '_boost/browser-logs'];

    public function test_every_application_page_sends_guests_to_the_login_page(): void
    {
        $checked = 0;

        foreach ($this->applicationRoutes() as $route) {
            $method = collect($route->methods())->first(fn (string $method) => $method !== 'HEAD');
            $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');

            $this->call($method, $uri)->assertRedirect(route('login'));
            $checked++;
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertGuest();
    }

    public function test_the_only_routes_open_to_guests_are_sign_in_registration_recovery_and_invitations(): void
    {
        $open = collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RouteDefinition $route) => in_array($route->uri(), self::FRAMEWORK_URIS, true))
            ->reject(fn (RouteDefinition $route) => in_array('auth', $route->gatherMiddleware(), true))
            ->map(fn (RouteDefinition $route) => $route->getName() ?? $route->methods()[0].' '.$route->uri())
            ->unique()->sort()->values()->all();

        $this->assertSame(collect([...self::PUBLIC_ROUTES, 'POST login', 'POST register'])
            ->unique()->sort()->values()->all(), $open);

        foreach ($open as $name) {
            if (Route::has($name)) {
                $this->assertContains('guest', Route::getRoutes()->getByName($name)->gatherMiddleware(), "[{$name}] must be guest-only.");
            }
        }
    }

    public function test_guests_can_still_sign_in_register_and_recover_a_password(): void
    {
        foreach ([route('login'), route('register'), route('password.request'), route('password.reset', ['token' => 'any', 'email' => 'a@example.com'])] as $url) {
            $this->get($url)->assertOk()->assertDontSee('How it works')->assertDontSee(route('doctors.index'), false);
        }

        foreach ([route('doctor.invitation.show', 'not-a-token'), route('assistant.invitation.show', 'not-a-token')] as $url) {
            $this->get($url.'?email=nobody@example.com')->assertOk()->assertSee('invalid or has expired')->assertSee(route('login'), false);
        }

        $this->post(route('register'), [
            'name' => 'New Patient',
            'email' => 'new.patient@example.com',
            'phone' => '01712345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('patient.dashboard'));
        $this->assertSame('patient', User::where('email', 'new.patient@example.com')->value('role'));
        $this->get(route('patient.dashboard'))->assertOk();
        $this->get(route('doctors.index'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login'), ['email' => 'new.patient@example.com', 'password' => 'password123'])->assertRedirect(route('patient.dashboard'));
    }

    /**
     * Every application route except the intentionally public and framework ones.
     *
     * @return list<RouteDefinition>
     */
    private function applicationRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RouteDefinition $route) => in_array($route->uri(), self::FRAMEWORK_URIS, true))
            ->reject(fn (RouteDefinition $route) => in_array($route->getName(), self::PUBLIC_ROUTES, true))
            ->reject(fn (RouteDefinition $route) => in_array($route->uri(), ['login', 'register'], true))
            ->values()->all();
    }
}
