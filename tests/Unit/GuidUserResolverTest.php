<?php

namespace Pagasa\Sso\Tests\Unit;

use Illuminate\Support\Facades\Hash;
use Pagasa\Sso\Resolvers\GuidUserResolver;
use Pagasa\Sso\SsoResult;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\Fixtures\User;
use Pagasa\Sso\Tests\TestCase;

class GuidUserResolverTest extends TestCase
{
    protected function resolve(array $profile = []): ?User
    {
        return $this->app->make(GuidUserResolver::class)
            ->resolve(SsoResult::fromResponse(SsoResponses::token($profile)), 'web');
    }

    protected function useEmployeeNumberFallback(): void
    {
        config(['sso.users.fallback' => ['profile' => 'employee_number', 'column' => 'employee_number']]);
    }

    public function test_a_guid_match_wins_and_syncs_attributes(): void
    {
        $existing = $this->makeUser(['guid' => 'g-1', 'username' => 'old', 'name' => 'Old Name', 'email' => 'keep@x']);

        $user = $this->resolve(['guid' => 'g-1', 'username' => 'new', 'name' => 'New Name', 'email' => null]);

        $this->assertTrue($user->is($existing));
        $this->assertSame('new', $user->username);
        $this->assertSame('New Name', $user->name);
        $this->assertSame('keep@x', $user->email, 'a blank SSO value never erases local data');
        $this->assertSame(1, User::count());
    }

    public function test_a_new_user_is_provisioned_with_an_unusable_password(): void
    {
        $user = $this->resolve(['guid' => 'g-new', 'username' => 'fresh']);

        $this->assertTrue($user->exists);
        $this->assertSame('g-new', $user->guid);
        $this->assertSame('fresh', $user->username);
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
    }

    public function test_provisioning_can_be_turned_off(): void
    {
        config(['sso.users.create' => false]);

        $this->assertNull($this->resolve(['guid' => 'g-new']));
        $this->assertSame(0, User::count());
    }

    public function test_without_a_guid_or_fallback_nobody_is_created(): void
    {
        $this->assertNull($this->resolve(['guid' => null]));
        $this->assertSame(0, User::count());
    }

    public function test_the_fallback_adopts_a_row_without_a_guid_and_stamps_it(): void
    {
        $this->useEmployeeNumberFallback();
        $legacy = $this->makeUser(['employee_number' => '0042']);

        $user = $this->resolve(['guid' => 'g-42', 'employee_number' => '0042']);

        $this->assertTrue($user->is($legacy));
        $this->assertSame('g-42', $legacy->fresh()->guid);
    }

    public function test_a_recycled_fallback_value_provisions_a_new_user(): void
    {
        $this->useEmployeeNumberFallback();
        config(['sso.users.release' => ['employee_number']]);
        $previousHolder = $this->makeUser(['guid' => 'g-old', 'employee_number' => '0042']);

        $user = $this->resolve(['guid' => 'g-new', 'employee_number' => '0042']);

        $this->assertFalse($user->is($previousHolder));
        $this->assertSame('g-new', $user->guid);
        $this->assertSame('0042', $user->employee_number);
        $this->assertNull($previousHolder->fresh()->employee_number, 'the recycled value is released');
        $this->assertSame('g-old', $previousHolder->fresh()->guid, 'the previous holder keeps its guid');
    }

    public function test_a_recycled_fallback_value_is_refused_when_provisioning_is_off(): void
    {
        $this->useEmployeeNumberFallback();
        config(['sso.users.create' => false]);
        $this->makeUser(['guid' => 'g-old', 'employee_number' => '0042']);

        $this->assertNull($this->resolve(['guid' => 'g-new', 'employee_number' => '0042']));
    }

    public function test_without_a_guid_the_fallback_match_is_adopted(): void
    {
        $this->useEmployeeNumberFallback();
        $adopted = $this->makeUser(['guid' => 'g-1', 'employee_number' => '0042']);

        $user = $this->resolve(['guid' => null, 'employee_number' => '0042']);

        $this->assertTrue($user->is($adopted));
        $this->assertSame('g-1', $adopted->fresh()->guid);
    }

    public function test_a_reassigned_username_is_released_from_its_previous_holder(): void
    {
        config(['sso.users.release' => ['username']]);
        $previousHolder = $this->makeUser(['guid' => 'g-old', 'username' => 'jdoe']);

        $user = $this->resolve(['guid' => 'g-new', 'username' => 'jdoe']);

        $this->assertSame('jdoe', $user->username);
        $this->assertNull($previousHolder->fresh()->username);
    }
}
