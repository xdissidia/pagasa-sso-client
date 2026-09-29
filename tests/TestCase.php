<?php

namespace Pagasa\Sso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Pagasa\Sso\SsoServiceProvider;
use Pagasa\Sso\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [SsoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('sso.url', 'https://sso.test/');
        $app['config']->set('sso.client_id', 'demo');
        $app['config']->set('sso.client_secret', 'demo-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 36)->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('employee_number')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->app['router']->get('/protected', function () {
            return 'protected:'.auth()->id();
        })->middleware(['web', 'sso.auth']);

        $this->app['router']->post('/protected', function () {
            return 'posted';
        })->middleware(['web', 'sso.auth']);

        $this->app['router']->get('/sensitive', function () {
            return 'sensitive';
        })->middleware(['web', 'sso.auth', 'sso.fresh:300']);
    }

    protected function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Existing User',
            'password' => 'x',
        ], $attributes));
    }
}
