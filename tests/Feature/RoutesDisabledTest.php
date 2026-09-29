<?php

namespace Pagasa\Sso\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Pagasa\Sso\Tests\TestCase;

class RoutesDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('sso.routes.enabled', false);
    }

    public function test_the_routes_can_be_turned_off(): void
    {
        $this->assertFalse(Route::has('sso.login'));
        $this->assertFalse(Route::has('sso.callback'));
    }
}
