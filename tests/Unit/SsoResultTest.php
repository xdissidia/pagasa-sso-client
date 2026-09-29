<?php

namespace Pagasa\Sso\Tests\Unit;

use Pagasa\Sso\SsoResult;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\TestCase;

class SsoResultTest extends TestCase
{
    public function test_freshness_is_measured_from_auth_time(): void
    {
        $result = SsoResult::fromResponse(SsoResponses::token([], ['auth_time' => 1000]));

        $this->assertSame(100, $result->ageInSeconds(1100));
        $this->assertTrue($result->isFresh(120, 1100));
        $this->assertTrue($result->isFresh(120, 1120));
        $this->assertFalse($result->isFresh(120, 1121));
    }

    public function test_a_null_auth_time_is_never_fresh(): void
    {
        $result = SsoResult::fromResponse(SsoResponses::token([], ['auth_time' => null, 'amr' => null]));

        $this->assertNull($result->authTime);
        $this->assertNull($result->amr);
        $this->assertNull($result->ageInSeconds());
        $this->assertFalse($result->isFresh(PHP_INT_MAX));
        $this->assertFalse($result->usedTwoFactor());
    }

    public function test_two_factor_means_otp_or_recovery_code(): void
    {
        foreach ([[['pwd'], false], [['pwd', 'otp'], true], [['rc'], true], [['otp'], true]] as [$amr, $expected]) {
            $result = SsoResult::fromResponse(SsoResponses::token([], ['amr' => $amr]));

            $this->assertSame($expected, $result->usedTwoFactor(), implode(',', $amr));
        }
    }

    public function test_groups_and_ou_are_read_from_the_response(): void
    {
        $result = SsoResult::fromResponse(SsoResponses::token(
            ['ou' => 'OU=Hydromet,OU=Central Office'],
            ['groups' => ['PMIS-Admin', 7, null, ['nested'], 'PMIS-Viewer']]
        ));

        $this->assertSame(['PMIS-Admin', 'PMIS-Viewer'], $result->groups, 'non-strings are dropped');
        $this->assertSame('OU=Hydromet,OU=Central Office', $result->user->ou);
        $this->assertSame('OU=Hydromet,OU=Central Office', $result->user->toArray()['ou']);
    }

    public function test_a_response_without_groups_means_no_groups(): void
    {
        // An SSO that predates group release sends neither key.
        $payload = SsoResponses::token();
        unset($payload['groups'], $payload['user']['ou']);

        $result = SsoResult::fromResponse($payload);

        $this->assertSame([], $result->groups);
        $this->assertNull($result->user->ou);
        $this->assertFalse($result->inGroup('PMIS-Admin'));
    }

    public function test_group_membership_is_any_of_and_case_insensitive(): void
    {
        $result = SsoResult::fromResponse(SsoResponses::token([], ['groups' => ['PMIS-Approver']]));

        $this->assertTrue($result->inGroup('pmis-approver'));
        $this->assertTrue($result->inGroup('PMIS-Admin', 'PMIS-Approver'));
        $this->assertFalse($result->inGroup('PMIS-Admin'));
        $this->assertFalse($result->inGroup('PMIS-'), 'names match whole, not by prefix');
        $this->assertFalse($result->inGroup());
    }

    public function test_raw_profile_fields_stay_reachable(): void
    {
        $result = SsoResult::fromResponse(SsoResponses::token(['employee_number' => ' 0042 ', 'extra' => 'x']));

        $this->assertSame('0042', $result->user->get('employee_number'));
        $this->assertSame('x', $result->user->get('extra'));
        $this->assertSame(' 0042 ', $result->user->raw()['employee_number']);
    }
}
