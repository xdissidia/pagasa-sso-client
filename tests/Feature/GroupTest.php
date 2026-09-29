<?php

namespace Pagasa\Sso\Tests\Feature;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Pagasa\Sso\Facades\Sso;
use Pagasa\Sso\PendingStates;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\TestCase;

class GroupTest extends TestCase
{
    protected function signInThroughSso(array $groups): void
    {
        SsoResponses::fake(['guid' => 'g-1'], ['groups' => $groups]);

        $this->withSession(SsoResponses::pendingState('st'))->get('/sso/callback?state=st&code=c');

        $this->assertAuthenticated();
    }

    public function test_sign_in_records_the_released_groups(): void
    {
        $this->signInThroughSso(['PMIS-Admin', 'PMIS-Viewer']);

        $this->assertSame(['PMIS-Admin', 'PMIS-Viewer'], Sso::groups());
        $this->assertTrue(Sso::inGroup('pmis-admin'));
        $this->assertFalse(Sso::inGroup('PMIS-Approver'));
    }

    public function test_a_member_of_any_listed_group_passes(): void
    {
        $this->signInThroughSso(['pmis-approver']);

        $this->get('/admin')->assertOk()->assertSee('admin');
    }

    public function test_a_non_member_is_forbidden(): void
    {
        $this->signInThroughSso(['PMIS-Viewer']);

        $this->get('/admin')->assertForbidden();
    }

    public function test_known_but_empty_groups_are_forbidden_not_refetched(): void
    {
        // The SSO released no groups (e.g. the app has no prefix yet). Going
        // back to the SSO would return the same answer, so no round-trip.
        $this->signInThroughSso([]);

        $this->assertSame([], Sso::groups());
        $this->get('/admin')->assertForbidden();
    }

    public function test_unknown_groups_are_fetched_through_the_sso_once(): void
    {
        // Signed in without the SSO, e.g. restored from a remember-me cookie.
        $user = $this->makeUser(['guid' => 'g-1']);
        $this->actingAs($user);

        $this->assertNull(Sso::groups());
        $this->assertFalse(Sso::inGroup('PMIS-Admin'));

        $location = $this->get('/admin?tab=2')->headers->get('Location');
        $this->assertStringStartsWith('https://sso.test/sso/authorize?', $location);
        $this->assertStringNotContainsString('prompt=login', $location);
        $this->assertSame('http://localhost/admin?tab=2', session('url.intended'));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        SsoResponses::fake(['guid' => 'g-1'], ['groups' => ['PMIS-Admin']]);

        $this->get('/sso/callback?state='.$query['state'].'&code=c')
            ->assertRedirect('http://localhost/admin?tab=2');

        $this->assertSame($user->getKey(), auth()->id());
        $this->get('/admin?tab=2')->assertOk();
    }

    public function test_unknown_groups_on_a_json_request_get_a_403(): void
    {
        $this->actingAs($this->makeUser());

        $this->getJson('/admin')->assertForbidden();
    }

    public function test_unknown_groups_with_sso_disabled_get_a_403(): void
    {
        $this->actingAs($this->makeUser());
        config(['sso.enabled' => false]);

        $this->get('/admin')->assertForbidden();
    }

    public function test_groups_do_not_carry_over_to_the_next_user(): void
    {
        $this->signInThroughSso(['PMIS-Admin']);
        auth()->logout();

        $this->actingAs($this->makeUser(['guid' => 'g-2']));

        $this->assertNull(Sso::groups());
        $this->assertStringStartsWith('https://sso.test/sso/authorize?', $this->get('/admin')->headers->get('Location'));
    }

    public function test_the_middleware_needs_a_group_name(): void
    {
        Route::get('/misconfigured', function () {
            return 'x';
        })->middleware(['web', 'sso.auth', 'sso.group']);

        $this->signInThroughSso(['PMIS-Admin']);
        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        $this->get('/misconfigured');
    }

    public function test_a_named_guard_fetches_and_checks_groups_on_that_guard(): void
    {
        config(['auth.guards.staff' => ['driver' => 'session', 'provider' => 'users']]);
        Route::get('/staff-admin', function () {
            return 'staff-admin:'.auth()->id();
        })->middleware(['web', 'sso.auth:staff', 'sso.group:PMIS-Admin']);

        // Groups recorded on the default guard say nothing about the staff guard.
        $this->signInThroughSso(['PMIS-Admin']);
        $user = $this->makeUser(['guid' => 'g-2']);
        $this->actingAs($user, 'staff');

        $location = $this->get('/staff-admin')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('staff', session(PendingStates::SESSION_KEY)[$query['state']]['guard']);

        SsoResponses::fake(['guid' => 'g-2', 'username' => 'staffer'], ['groups' => ['PMIS-Admin']]);
        $this->get('/sso/callback?state='.$query['state'].'&code=c')
            ->assertRedirect('http://localhost/staff-admin');

        $this->assertSame(['PMIS-Admin'], Sso::groups(null, 'staff'));
        $this->assertSame($user->getKey(), auth('staff')->id());
        $this->get('/staff-admin')->assertOk()->assertSee('staff-admin:'.$user->getKey());
    }
}
