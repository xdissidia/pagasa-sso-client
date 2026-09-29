<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The same env vars the hand-rolled clients already use, so an app moving
    | onto this package keeps its .env as-is. SSO is treated as disabled until
    | url, client_id and client_secret are all set, whatever SSO_ENABLED says.
    |
    | verify: true, false, or a path to a CA bundle. Leave it on - the token
    | request carries the client secret. For an internal CA, point it at the
    | CA file rather than turning verification off.
    |
    */

    'enabled' => (bool) env('SSO_ENABLED', true),

    'url' => env('SSO_URL'),

    'client_id' => env('SSO_CLIENT_ID'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    'verify' => env('SSO_VERIFY_SSL', true),

    'timeout' => (int) env('SSO_TIMEOUT', 10),

    // Defaults to route('sso.callback'). Set it when the app sits behind a
    // proxy that makes the generated URL differ (http vs https, host, path)
    // from the redirect_uri registered on the SSO - they must match exactly.
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Signing in
    |--------------------------------------------------------------------------
    |
    | guard: null means the app's default guard.
    |
    | remember: off by default. A long-lived cookie means a prompt=login
    | step-up rarely happens in practice, which defeats the point of asking
    | the SSO to re-verify.
    |
    | home: where to land after sign-in when no intended URL is stored.
    |
    | failure_redirect: a route name or URL. Failures redirect there with the
    | reason flashed as `sso_error`. null aborts with a 403 instead.
    |
    | max_auth_age: how old (in seconds) a prompt=login verification may be.
    | The SSO guarantees 60s; the slack absorbs clock drift between hosts.
    |
    */

    'guard' => null,

    'remember' => false,

    'home' => '/',

    'failure_redirect' => null,

    'max_auth_age' => 120,

    'log_channel' => env('SSO_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | GET {prefix}/login     sso.login     starts sign-in (?prompt=login to step up)
    | GET {prefix}/callback  sso.callback  must match the redirect_uri registered
    |                                      with `php artisan sso:client` on the SSO
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'sso',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    |
    | resolver: maps an SSO profile onto a local user. Implement
    | Pagasa\Sso\Contracts\UserResolver when the default below does not fit
    | (e.g. users are matched through another table, or never auto-created).
    |
    | failure_responder: turns a failed sign-in into a response. Implement
    | Pagasa\Sso\Contracts\FailureResponder to render your own page.
    |
    */

    'resolver' => Pagasa\Sso\Resolvers\GuidUserResolver::class,

    'failure_responder' => Pagasa\Sso\Http\DefaultFailureResponder::class,

    /*
    |--------------------------------------------------------------------------
    | Default resolver (GuidUserResolver)
    |--------------------------------------------------------------------------
    |
    | Matches on the immutable AD objectGUID first. The fallback lets an app
    | adopt users that predate the guid rollout: a row found by the fallback
    | column is adopted only if it has no guid of its own, then stamped.
    | A row already bound to a different guid means the value was recycled,
    | and a new user is provisioned instead of rebinding it.
    |
    | fallback: ['profile' => SSO profile field, 'column' => users column],
    | e.g. ['profile' => 'employee_number', 'column' => 'employee_number'],
    | or null to match on guid alone (the right choice for a new app).
    |
    | create: provision a user on first sign-in. When false, only existing
    | users can sign in and everyone else gets `no_account`.
    |
    | sync: SSO profile field => users column, written on every sign-in.
    | Available fields: guid, username, name, email, department, title,
    | employee_number. A blank SSO value never overwrites local data.
    |
    | password_column: new users get an unusable random password here, so a
    | NOT NULL password column needs no migration. null to skip.
    |
    | release: unique columns whose value may be recycled in AD (username,
    | employee_number). Before writing, the value is cleared from any other
    | row still holding it - that row keeps its guid and still matches its
    | owner. Without this a recycled value hits the unique index and the
    | sign-in fails. Only list nullable columns.
    |
    */

    'users' => [
        'model' => null, // null: the model of the guard's user provider

        'guid_column' => 'guid',

        'fallback' => null,

        'create' => true,

        'sync' => [
            'username' => 'username',
            'name' => 'name',
            'email' => 'email',
        ],

        'password_column' => 'password',

        'release' => [],
    ],

];
