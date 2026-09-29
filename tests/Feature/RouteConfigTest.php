<?php

namespace Pagasa\Sso\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Pagasa\Sso\Tests\TestCase;

class RouteConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('sso.routes.prefix', 'auth/pagasa');
    }

    public function test_the_prefix_is_configurable(): void
    {
        $this->assertSame('http://localhost/auth/pagasa/callback', route('sso.callback'));
        $this->assertSame('http://localhost/auth/pagasa/login', route('sso.login'));
    }

    public function test_the_middleware_aliases_are_registered(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertArrayHasKey('sso.auth', $aliases);
        $this->assertArrayHasKey('sso.fresh', $aliases);
        $this->assertTrue(Route::has('sso.login'));
    }
}
