<?php

namespace Pagasa\Sso\Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use Pagasa\Sso\PendingStates;
use Pagasa\Sso\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_sso_with_a_remembered_state(): void
    {
        $response = $this->get('/protected?tab=2');

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://sso.test/sso/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('demo', $query['client_id']);
        $this->assertSame('http://localhost/sso/callback', $query['redirect_uri']);
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertSame(40, strlen($query['state']));

        $pending = session(PendingStates::SESSION_KEY);
        $this->assertArrayHasKey($query['state'], $pending);
        $this->assertFalse($pending[$query['state']]['step_up']);
        $this->assertSame('http://localhost/protected?tab=2', session('url.intended'));
    }

    public function test_an_inertia_visit_gets_a_409_location_instead_of_a_redirect(): void
    {
        $this->get('/protected', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');

        $this->assertStringStartsWith(
            'https://sso.test/sso/authorize?',
            $this->get('/protected', ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location')
        );
    }

    public function test_a_json_request_gets_a_401(): void
    {
        $this->getJson('/protected')->assertStatus(401);
    }

    public function test_when_sso_is_disabled_the_apps_own_auth_handling_takes_over(): void
    {
        config(['sso.enabled' => false]);
        $this->withoutExceptionHandling();

        $this->expectException(AuthenticationException::class);

        $this->get('/protected');
    }

    public function test_a_signed_in_user_passes_through(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/protected')->assertOk()->assertSee('protected:'.$user->id);
    }

    public function test_a_non_get_request_returns_to_the_previous_page_afterwards(): void
    {
        $this->from('/form')->post('/protected');

        $this->assertSame('http://localhost/form', session('url.intended'));
    }

    public function test_a_guard_can_be_named(): void
    {
        config([
            'auth.guards.staff' => ['driver' => 'session', 'provider' => 'users'],
        ]);

        $this->app['router']->get('/staff', function () {
            return 'staff:'.auth()->id();
        })->middleware(['web', 'sso.auth:staff']);

        $response = $this->get('/staff');
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('staff', session(PendingStates::SESSION_KEY)[$query['state']]['guard']);

        $user = $this->makeUser();
        $this->actingAs($user, 'staff')->get('/staff')->assertSee('staff:'.$user->id);
    }
}
