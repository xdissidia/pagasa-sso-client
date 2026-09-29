<?php

namespace Pagasa\Sso\Tests\Unit;

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Pagasa\Sso\PendingStates;
use Pagasa\Sso\Tests\TestCase;

class PendingStatesTest extends TestCase
{
    protected function states(): PendingStates
    {
        return new PendingStates(new Store('test', new ArraySessionHandler(10)));
    }

    public function test_a_state_can_be_pulled_exactly_once(): void
    {
        $states = $this->states();
        $states->put('abc', true, 'admin');

        $entry = $states->pull('abc');

        $this->assertTrue($entry['step_up']);
        $this->assertSame('admin', $entry['guard']);
        $this->assertNull($states->pull('abc'));
    }

    public function test_unknown_empty_and_non_string_states_are_rejected(): void
    {
        $states = $this->states();
        $states->put('abc', false);

        $this->assertNull($states->pull('abd'));
        $this->assertNull($states->pull(''));
        $this->assertNull($states->pull(null));
        $this->assertNull($states->pull(['abc']));
        $this->assertNotNull($states->pull('abc'), 'failed lookups do not consume other states');
    }

    public function test_several_tabs_can_be_in_flight_at_once(): void
    {
        $states = $this->states();
        $states->put('tab1', false);
        $states->put('tab2', false);
        $states->put('tab3', true);

        $this->assertNotNull($states->pull('tab2'));
        $this->assertNotNull($states->pull('tab1'));
        $this->assertTrue($states->pull('tab3')['step_up']);
    }

    public function test_only_the_newest_states_are_kept(): void
    {
        $states = $this->states();
        $now = time();

        for ($i = 1; $i <= PendingStates::MAX + 2; $i++) {
            $states->put("s{$i}", false, null, $now + $i);
        }

        $this->assertNull($states->pull('s1', $now + 10));
        $this->assertNull($states->pull('s2', $now + 10));
        $this->assertNotNull($states->pull('s3', $now + 10));
        $this->assertNotNull($states->pull('s'.(PendingStates::MAX + 2), $now + 10));
    }

    public function test_states_expire(): void
    {
        $states = $this->states();
        $states->put('old', false, null, 1000);

        $this->assertNull($states->pull('old', 1000 + PendingStates::TTL + 1));
    }

    public function test_numeric_looking_states_still_match(): void
    {
        $states = $this->states();
        $states->put('1234567890', false);

        $this->assertNotNull($states->pull('1234567890'));
    }
}
