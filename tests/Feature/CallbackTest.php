<?php

namespace Pagasa\Sso\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pagasa\Sso\Events\SsoAuthenticated;
use Pagasa\Sso\Events\SsoFailed;
use Pagasa\Sso\SsoClient;
use Pagasa\Sso\SsoException;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\TestCase;

class CallbackTest extends TestCase
{
    public function test_a_valid_callback_signs_the_user_in(): void
    {
        Event::fake([SsoAuthenticated::class, SsoFailed::class]);
        SsoResponses::fake(['guid' => 'g-1', 'username' => 'jdoe'], ['auth_time' => 1765000000, 'amr' => ['pwd', 'otp']]);

        $this->withSession(SsoResponses::pendingState('st') + ['url.intended' => 'http://localhost/protected'])
            ->get('/sso/callback?state=st&code=the-code')
            ->assertRedirect('http://localhost/protected');

        $this->assertAuthenticated();
        $this->assertSame('g-1', auth()->user()->guid);

        Http::assertSent(function (Request $request) {
            return $request['code'] === 'the-code';
        });

        Event::assertDispatched(SsoAuthenticated::class, function (SsoAuthenticated $event) {
            return $event->user->guid === 'g-1' && $event->stepUp === false && $event->guard === 'web';
        });
        Event::assertNotDispatched(SsoFailed::class);

        $sso = $this->app->make(SsoClient::class);
        $this->assertSame(1765000000, $sso->authTime());
        $this->assertSame(['pwd', 'otp'], $sso->amr());
    }

    public function test_without_an_intended_url_the_user_lands_on_home(): void
    {
        config(['sso.home' => '/dashboard']);
        SsoResponses::fake();

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=st&code=c')
            ->assertRedirect('http://localhost/dashboard');
    }

    public function test_the_session_is_regenerated_on_sign_in(): void
    {
        SsoResponses::fake();

        $this->withSession(SsoResponses::pendingState('st'));
        $before = $this->app['session']->getId();

        $this->get('/sso/callback?state=st&code=c');

        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_an_unknown_state_is_refused_without_calling_the_sso(): void
    {
        Event::fake([SsoFailed::class]);
        Http::fake();

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=forged&code=c')
            ->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
        Event::assertDispatched(SsoFailed::class, function (SsoFailed $event) {
            return $event->exception->reason() === SsoException::STATE;
        });
    }

    public function test_a_missing_state_is_refused(): void
    {
        Http::fake();

        $this->get('/sso/callback?code=c')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        SsoResponses::fake();

        $this->withSession(SsoResponses::pendingState('st'))->get('/sso/callback?state=st&code=c');
        auth()->logout();

        $this->get('/sso/callback?state=st&code=c')->assertForbidden();
    }

    public function test_a_replayed_callback_while_signed_in_just_goes_home(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/sso/callback?state=stale&code=c')
            ->assertRedirect('http://localhost');
    }

    public function test_a_missing_code_is_refused(): void
    {
        Http::fake();

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=st')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_refused_exchange_is_refused(): void
    {
        SsoResponses::fakeError('invalid_grant', 400);

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=st&code=used')
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_a_profile_with_no_local_account_is_refused(): void
    {
        config(['sso.users.create' => false]);
        SsoResponses::fake();

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=st&code=c')
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_failures_redirect_to_the_failure_page_with_the_reason(): void
    {
        Route::get('/login', function () {
            return 'login page';
        })->name('login');
        $this->app['router']->getRoutes()->refreshNameLookups();

        config(['sso.failure_redirect' => 'login']);
        SsoResponses::fakeError('invalid_client', 401);

        $this->withSession(SsoResponses::pendingState('st'))
            ->get('/sso/callback?state=st&code=c')
            ->assertRedirect('http://localhost/login')
            ->assertSessionHas('sso_error', SsoException::INVALID_CLIENT);
    }

    public function test_failure_redirect_also_accepts_a_path(): void
    {
        config(['sso.failure_redirect' => '/signin']);

        $this->get('/sso/callback?state=x&code=c')
            ->assertRedirect('http://localhost/signin')
            ->assertSessionHas('sso_error', SsoException::STATE);
    }

    public function test_a_different_user_is_not_silently_swapped_in(): void
    {
        $signedIn = $this->makeUser(['guid' => 'g-me']);
        SsoResponses::fake(['guid' => 'g-someone-else']);

        $this->actingAs($signedIn)
            ->withSession(SsoResponses::pendingState('st', true))
            ->get('/sso/callback?state=st&code=c')
            ->assertForbidden();

        $this->assertSame($signedIn->id, auth()->id());
    }

    public function test_remember_me_follows_config(): void
    {
        config(['sso.remember' => true]);
        SsoResponses::fake(['guid' => 'g-1']);

        $response = $this->withSession(SsoResponses::pendingState('st'))->get('/sso/callback?state=st&code=c');

        $this->assertNotNull(auth()->user()->getRememberToken());
        $this->assertTrue(collect($response->headers->getCookies())->contains(function ($cookie) {
            return str_starts_with($cookie->getName(), 'remember_web_');
        }));
    }
}
