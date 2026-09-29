<?php

namespace Pagasa\Sso\Tests\Feature;

use Pagasa\Sso\PendingStates;
use Pagasa\Sso\SsoException;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\TestCase;

class StepUpTest extends TestCase
{
    protected function signInThroughSso(array $claims = []): void
    {
        SsoResponses::fake(['guid' => 'g-1'], $claims);

        $this->withSession(SsoResponses::pendingState('st'))->get('/sso/callback?state=st&code=c');

        $this->assertAuthenticated();
    }

    public function test_a_recent_verification_passes(): void
    {
        $this->signInThroughSso(['auth_time' => time() - 60]);

        $this->get('/sensitive')->assertOk()->assertSee('sensitive');
    }

    public function test_a_stale_verification_triggers_a_prompt_login_round_trip(): void
    {
        $this->signInThroughSso(['auth_time' => time() - 301]);

        $location = $this->get('/sensitive')->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('login', $query['prompt']);
        $this->assertTrue(session(PendingStates::SESSION_KEY)[$query['state']]['step_up']);
        $this->assertSame('http://localhost/sensitive', session('url.intended'));
    }

    public function test_a_session_restored_from_a_remember_cookie_is_never_fresh(): void
    {
        $this->signInThroughSso(['auth_time' => null, 'amr' => null]);

        $this->assertStringContainsString('prompt=login', $this->get('/sensitive')->headers->get('Location'));
    }

    public function test_a_user_who_did_not_sign_in_through_sso_is_never_fresh(): void
    {
        $this->actingAs($this->makeUser());

        $this->assertStringContainsString('prompt=login', $this->get('/sensitive')->headers->get('Location'));
    }

    public function test_freshness_does_not_carry_over_to_the_next_user(): void
    {
        $this->signInThroughSso(['auth_time' => time()]);
        auth()->logout();

        $this->actingAs($this->makeUser(['guid' => 'g-2']));

        $this->assertStringContainsString('prompt=login', $this->get('/sensitive')->headers->get('Location'));
    }

    public function test_the_step_up_callback_rejects_a_stale_claim(): void
    {
        SsoResponses::fake(['guid' => 'g-1'], ['auth_time' => time() - 121]);

        $this->withSession(SsoResponses::pendingState('st', true))
            ->get('/sso/callback?state=st&code=c')
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_the_step_up_callback_rejects_a_missing_claim(): void
    {
        config(['sso.failure_redirect' => '/oops']);
        SsoResponses::fake(['guid' => 'g-1'], ['auth_time' => null]);

        $this->withSession(SsoResponses::pendingState('st', true))
            ->get('/sso/callback?state=st&code=c')
            ->assertSessionHas('sso_error', SsoException::STALE);
    }

    public function test_a_completed_step_up_refreshes_the_verification(): void
    {
        $this->signInThroughSso(['auth_time' => time() - 1000]);

        SsoResponses::fake(['guid' => 'g-1'], ['auth_time' => time() - 5, 'amr' => ['otp']]);
        $this->withSession(SsoResponses::pendingState('up', true) + ['url.intended' => 'http://localhost/sensitive'])
            ->get('/sso/callback?state=up&code=c2')
            ->assertRedirect('http://localhost/sensitive');

        $this->get('/sensitive')->assertOk();
    }

    public function test_a_json_request_gets_a_403_instead_of_a_redirect(): void
    {
        $this->signInThroughSso(['auth_time' => time() - 1000]);

        $this->getJson('/sensitive')->assertForbidden();
    }

    public function test_a_named_guard_steps_up_on_that_guard(): void
    {
        config(['auth.guards.staff' => ['driver' => 'session', 'provider' => 'users']]);
        $this->app['router']->get('/staff-sensitive', function () {
            return 'staff-sensitive';
        })->middleware(['web', 'sso.auth:staff', 'sso.fresh:300']);

        // A fresh sign-in on the default guard does not make the staff guard fresh.
        $this->signInThroughSso(['auth_time' => time() - 60]);
        $this->actingAs($this->makeUser(['guid' => 'g-2']), 'staff');

        $location = $this->get('/staff-sensitive')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('login', $query['prompt']);
        $this->assertSame('staff', session(PendingStates::SESSION_KEY)[$query['state']]['guard']);
    }
}
