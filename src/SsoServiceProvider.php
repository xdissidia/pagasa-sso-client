<?php

namespace Pagasa\Sso;

use Illuminate\Support\ServiceProvider;
use Pagasa\Sso\Contracts\FailureResponder;
use Pagasa\Sso\Contracts\UserResolver;
use Pagasa\Sso\Http\DefaultFailureResponder;
use Pagasa\Sso\Http\Middleware\EnsureSsoAuthenticated;
use Pagasa\Sso\Http\Middleware\RequireFreshAuthentication;
use Pagasa\Sso\Resolvers\GuidUserResolver;

class SsoServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso.php', 'sso');

        $this->app->singleton(SsoClient::class, function ($app) {
            return new SsoClient($app['config']);
        });

        $this->app->bind(UserResolver::class, function ($app) {
            return $app->make($app['config']->get('sso.resolver') ?: GuidUserResolver::class);
        });

        $this->app->bind(FailureResponder::class, function ($app) {
            return $app->make($app['config']->get('sso.failure_responder') ?: DefaultFailureResponder::class);
        });
    }

    public function boot()
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('sso.auth', EnsureSsoAuthenticated::class);
        $router->aliasMiddleware('sso.fresh', RequireFreshAuthentication::class);

        if ($this->app['config']->get('sso.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sso');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sso.php' => config_path('sso.php'),
            ], 'sso-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/sso'),
            ], 'sso-views');

            $this->publishes([
                __DIR__.'/../database/migrations/add_sso_columns_to_users_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_add_sso_columns_to_users_table.php'
                ),
            ], 'sso-migrations');
        }
    }
}
