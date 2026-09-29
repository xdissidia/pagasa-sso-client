# pagasa/sso-client

Sign users in to a Laravel app through **PAGASA SSO**. Install the package, set
three env vars, and put `sso.auth` on your routes. The package covers:

- the redirect to the SSO
- the callback and its state check
- the server-to-server code exchange
- step-up re-verification
- matching the SSO profile to a local user

Your app only decides how users are matched, and only if the default doesn't fit.

- PHP 8.0+ and Laravel 8 – 13
- Works with Blade and Inertia apps

## Contents

1. [Install](#install)
2. [Register the app on the SSO](#register-the-app-on-the-sso)
3. [Configure](#configure)
4. [Protect routes](#protect-routes)
5. [Step-up: re-verify before sensitive actions](#step-up-re-verify-before-sensitive-actions)
6. [Matching SSO users to local users](#matching-sso-users-to-local-users)
7. [When sign-in fails](#when-sign-in-fails)
8. [Events, logging, logout](#events-logging-logout)
9. [Testing your app](#testing-your-app)
10. [Moving from a hand-rolled client](#moving-from-a-hand-rolled-client)

## Install

The package is not on Packagist. Add its repository to your app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/xdissidia/pagasa-sso-client" }
]
```

```bash
composer require pagasa/sso-client
```

The repository is private, so Composer needs a GitHub token that can read it.
Set it once per machine or deploy server:

```bash
composer config --global github-oauth.github.com <token>
```

The service provider and the `Sso` facade register themselves.

> **Laravel 8 apps** need PHP 8.0 or newer on every server, deploy servers
> included. A plain `composer require` leaves your locked `laravel/framework`
> where it is. A full `composer update` on Laravel 8 fails on Composer 2.9+,
> because every Laravel 8 release has published security advisories. That is
> not caused by this package.

## Register the app on the SSO

On the **SSO server**, run:

```bash
php artisan sso:client "PMIS" https://pmis.pagasa.gov/sso/callback --app-url=https://pmis.pagasa.gov
```

The URL must be your app's callback, `{APP_URL}/sso/callback`. It must match
**exactly**: scheme, host and path. The command prints `SSO_CLIENT_ID` and
`SSO_CLIENT_SECRET` once, and the secret cannot be recovered afterwards.

## Configure

```dotenv
SSO_URL=https://sso.pagasa.gov
SSO_CLIENT_ID=pmis
SSO_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Optional
SSO_ENABLED=true          # false: sso.auth falls back to your app's own login
SSO_VERIFY_SSL=true       # or a path to your internal CA bundle; never turn it off in production
SSO_TIMEOUT=10
SSO_REDIRECT_URI=         # only if route('sso.callback') differs from the registered URL (e.g. behind a proxy)
SSO_LOG_CHANNEL=
```

These are the env vars the older hand-rolled clients read, so an app moving
to the package keeps its `.env` unchanged.

Publish the config file only if you want to change the defaults:

```bash
php artisan vendor:publish --tag=sso-config
```

### Users table

Your `users` table needs a nullable unique `guid` column, and a `username`
column if you sync usernames. The package ships a migration that adds
whichever of the two is missing:

```bash
php artisan vendor:publish --tag=sso-migrations
php artisan migrate
```

- Some AD accounts have no email address, and the SSO sends `null` for them.
  If your `email` column is `NOT NULL`, make it nullable or remove `email`
  from `sso.users.sync`.
- New users get an unusable random password, so a `NOT NULL` `password`
  column is fine.

## Protect routes

```php
Route::middleware('sso.auth')->group(function () {
    Route::get('/', DashboardController::class);
    // ...
});
```

`sso.auth` works like `auth`. How it treats a guest depends on the request:

| Request | What happens |
| --- | --- |
| Normal page | Sent to the SSO, then brought back to the page they asked for |
| Inertia visit | Gets `409` + `X-Inertia-Location`, so Inertia does a full-page visit to the SSO. An XHR cannot follow a redirect to another origin. |
| JSON | Gets a `401` |
| Any request, when `SSO_ENABLED=false` | `AuthenticationException` is thrown, so your app's own login page takes over |

To use a guard other than the default, name it: `sso.auth:admin`.

The package also registers two routes. Both use the `web` middleware group:

| Route | Name | Purpose |
| --- | --- | --- |
| `GET /sso/login` | `sso.login` | Starts sign-in; point your sign-in button here |
| `GET /sso/callback` | `sso.callback` | Where the SSO sends the user back |

Change the prefix with `sso.routes.prefix`. To declare the routes yourself,
turn them off with `sso.routes.enabled = false`.

### Sign-in button

```blade
@include('sso::button')                          {{-- dark surface --}}
@include('sso::button', ['light' => true])       {{-- light surface --}}
@include('sso::button', ['divider' => 'or'])     {{-- with an "or" rule above --}}
```

The button links to `sso.login`, and the PAGASA seal loads from the SSO host.
For Inertia/Vue apps, copy the markup from the SSO repo's
`docs/pagasa-sso-button.html` and link it to `/sso/login` with a plain `<a>`,
not `<Link>`.

## Step-up: re-verify before sensitive actions

```php
Route::middleware(['sso.auth', 'sso.fresh:300'])->group(function () {
    Route::get('/payroll/{id}/approve', [PayrollController::class, 'confirm']);
    Route::post('/payroll/{id}/approve', [PayrollController::class, 'approve']);
});
```

`sso.fresh:{seconds}` checks when the user last verified at the SSO. If that
was more than `{seconds}` ago, the user goes through `prompt=login`:

- The SSO asks for their authenticator code, or their AD password if they
  haven't set up two-factor.
- Then it sends them back.

When no value is given, the limit defaults to `sso.max_auth_age` (120s).

The callback checks the `auth_time` claim server-side. If the SSO comes back
with a *different* person than the one already signed in, the callback
refuses rather than switching accounts.

A POST can't be replayed after the round-trip. A stale POST returns the user
to the previous page, and they submit again. Put `sso.fresh` on the GET page
that shows the form as well, so the step-up normally happens before the user
fills it in.

To check the claims yourself:

```php
use Pagasa\Sso\Facades\Sso;

Sso::authTime();   // Unix time of the last verification, or null
Sso::amr();        // ['pwd'], ['pwd', 'otp'], ['otp'], ['rc'] ...
```

## Matching SSO users to local users

The SSO sends this profile:

```
guid, username, name, email, department, title, employee_number
```

**Only `guid` never changes**; it is AD's objectGUID. `employee_number` and
`username` can be reassigned to someone else. Key your users on `guid`.

The default resolver, `GuidUserResolver`, is configured under `sso.users`:

```php
'users' => [
    'model' => null,             // default: the guard's provider model
    'guid_column' => 'guid',
    'fallback' => null,          // e.g. ['profile' => 'employee_number', 'column' => 'employee_number']
    'create' => true,            // provision new users on first sign-in
    'sync' => [                  // profile field => column, written on every sign-in
        'username' => 'username',
        'name' => 'name',
        'email' => 'email',
    ],
    'password_column' => 'password',
    'release' => [],             // e.g. ['username', 'employee_number']
],
```

It matches in this order:

1. **guid match**: that row is the user.
2. **fallback match** (only if `fallback` is set): the row is adopted only if
   it has **no guid of its own**, and then the guid is stamped on it. Use this
   to adopt users who existed before the SSO.
3. **fallback match already bound to a different guid**: the value was
   recycled to a new hire. A new user is created instead of taking over the
   old account.
4. **no match**: a new user is created if `create` is on. Otherwise the
   sign-in fails with `no_account`.

Two more rules apply:

- A blank SSO value never overwrites local data.
- For the unique columns listed in `release`, a value still held by another
  row is cleared from that row first. That row keeps its guid, so it still
  matches its owner. Without this, a recycled username fails the sign-in on
  the unique index.

### Your own resolver

Write your own when users are matched through another table, or when HR
provisions accounts and they must never be created automatically:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Pagasa\Sso\Contracts\UserResolver;
use Pagasa\Sso\SsoResult;

class HrisUserResolver implements UserResolver
{
    public function resolve(SsoResult $result, string $guard): ?Authenticatable
    {
        $profile = $result->user;   // ->guid, ->username, ->employeeNumber, ->get('employee_number'), ...

        return User::where('guid', $profile->guid)->first(); // null refuses the sign-in
    }
}
```

```php
// config/sso.php
'resolver' => App\Sso\HrisUserResolver::class,
```

## When sign-in fails

By default a failed sign-in is a `403`. For a friendlier page, send users to
your login route with the reason flashed:

```php
// config/sso.php
'failure_redirect' => 'login',   // route name or path
```

```blade
@if (session('sso_error'))
    <div class="alert">Sign-in failed ({{ session('sso_error') }}). Please try again.</div>
@endif
```

| `sso_error` | Meaning |
| --- | --- |
| `state` | Callback state unknown or expired (a stale bookmark, or a forged request) |
| `unreachable` | Could not reach the SSO (DNS, timeout, TLS) |
| `invalid_client` | Wrong `SSO_CLIENT_ID` / `SSO_CLIENT_SECRET` |
| `invalid_grant` | The code expired (60s), was already used, or was missing |
| `invalid_response` | The SSO answered with something unexpected (429, 5xx) |
| `stale` | A step-up came back without a fresh verification |
| `no_account` | No local user matches, and provisioning is off |
| `user_mismatch` | The SSO returned a different person than the one signed in |

To fully control the response, bind your own
`Pagasa\Sso\Contracts\FailureResponder` in `sso.failure_responder`.

## Events, logging, logout

- `Pagasa\Sso\Events\SsoAuthenticated`: carries `$user`, `$result` (the full
  `SsoResult`), `$stepUp` and `$guard`. Listen to it to copy
  `department`/`title` elsewhere, or to write an audit trail.
- `Pagasa\Sso\Events\SsoFailed`: carries `$exception` (`->reason()`,
  `->context()`) and `$request`.
- The package logs every sign-in and failure to `SSO_LOG_CHANNEL`, or to the
  default channel. It never logs the client secret.

**Logout** is yours: `Auth::logout()` plus `session()->invalidate()` ends the
session in your app only. The SSO has no logout API. To end the SSO session
too, send the browser to `{SSO_URL}` so the user can sign out there.

## Testing your app

`Pagasa\Sso\Testing\SsoResponses` fakes the SSO:

```php
use Pagasa\Sso\Testing\SsoResponses;

public function test_staff_can_sign_in(): void
{
    SsoResponses::fake(['guid' => 'g-1', 'username' => 'jdoe']);

    $this->withSession(SsoResponses::pendingState('st'))
        ->get('/sso/callback?state=st&code=anything')
        ->assertRedirect('/');

    $this->assertAuthenticated();
}
```

- `SsoResponses::fake($userOverrides, $claims)`: a successful exchange. Call
  it again to replace it, for example to sign in and then step up.
- `SsoResponses::fakeError('invalid_client', 401)`: a refused exchange.
- `SsoResponses::pendingState($state, $stepUp = false)`: session data that
  makes `$state` valid.
- `SsoResponses::token()` / `SsoResponses::user()`: raw response bodies.

## Moving from a hand-rolled client

The package keeps the callback at `/sso/callback`, named `sso.callback`, and
reads the same env vars as the hand-rolled clients. The client registered on
the SSO stays as it is.

1. `composer require pagasa/sso-client`.
2. Delete your `SsoCallbackController`, your `SsoRedirectController` or
   `EnsureSsoAuthenticated` middleware, and their routes.
3. Swap `auth` or your own middleware for `sso.auth`.
4. If your matching rules differ from the defaults, move them into a
   `UserResolver`. Examples: HRIS matches through `Employee`, and Chronos
   requires `employee_number`.
5. Run your SSO tests. Rewrite `Http::fake` of the token URL with
   `SsoResponses`.

## Protocol reference

For non-Laravel clients, see "Endpoint reference" in the SSO repo's README.

1. The browser goes to
   `GET {SSO_URL}/sso/authorize?client_id&redirect_uri&state[&prompt=login]`.
2. The SSO sends it back to `{redirect_uri}?code&state`.
3. The client calls `POST {SSO_URL}/api/sso/token` (form: `client_id`,
   `client_secret`, `code`) and gets back `{user, auth_time, amr}`.
