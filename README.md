# pagasa/sso-client

Sign users in to a Laravel app through **PAGASA SSO**. Install the package, set
three env vars, and put `sso.auth` on your routes. The package covers:

- the redirect to the SSO
- the callback and its state check
- the server-to-server code exchange
- step-up re-verification
- route access by AD group
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
6. [Groups: permissions from AD](#groups-permissions-from-ad)
7. [Matching SSO users to local users](#matching-sso-users-to-local-users)
8. [When sign-in fails](#when-sign-in-fails)
9. [Events, logging, logout](#events-logging-logout)
10. [Testing your app](#testing-your-app)
11. [Moving from a hand-rolled client](#moving-from-a-hand-rolled-client)

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
php artisan sso:client "PMIS" https://pmis.pagasa.gov/sso/callback --app-url=https://pmis.pagasa.gov --group-prefix=PMIS-
```

The URL must be your app's callback, `{APP_URL}/sso/callback`. It must match
**exactly**: scheme, host and path. The command prints `SSO_CLIENT_ID` and
`SSO_CLIENT_SECRET` once, and the secret cannot be recovered afterwards.

`--group-prefix` is optional. It releases the user's AD groups that start with
it to your app (see [Groups](#groups-permissions-from-ad)). To set or remove it
on an app that is already registered:
`php artisan sso:client-groups pmis PMIS-` or `php artisan sso:client-groups pmis --clear`.

## Configure

```dotenv
SSO_URL=https://sso2.pagasa.co
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
@include('sso::button', ['label' => 'Continue']) {{-- custom label --}}
```

The button links to `sso.login`, and the PAGASA seal loads from the SSO host.
Restyle it by setting the `--pagasa-sso-*` CSS variables used in the snippet
below on any ancestor. To change it further, publish the view with
`php artisan vendor:publish --tag=sso-views`.

#### Without Blade (Inertia/Vue, plain HTML)

This snippet is taken from the SSO repo's `docs/pagasa-sso-button.html`, which
also has a live preview of it. Link the button to `/sso/login` with a plain `<a>`,
not Inertia's `<Link>`: the sign-in leaves your app for the SSO, and an XHR
cannot follow that redirect.

```html
<div class="pagasa-sso-divider">or</div>

<a class="pagasa-sso-btn" href="/sso/login">
    <span class="pagasa-sso-btn__logo" aria-hidden="true"></span>
    <span>Sign in with PAGASA SSO</span>
</a>

<!-- Light surfaces: class="pagasa-sso-btn pagasa-sso-btn--light" -->
```

```css
.pagasa-sso-divider {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    margin: 1.5rem 0;
    font: 600 0.8125rem/1 var(--pagasa-sso-font, inherit);
    color: var(--pagasa-sso-muted, #94a3b8);
    user-select: none;
}
.pagasa-sso-divider::before,
.pagasa-sso-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--pagasa-sso-border, #33415580);
}

.pagasa-sso-btn {
    display: flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    box-sizing: border-box;
    padding: 0.75rem 1rem;
    border: 1px solid var(--pagasa-sso-border, #334155);
    border-radius: 0.625rem;
    background: var(--pagasa-sso-bg, #1e293b);
    color: var(--pagasa-sso-fg, #e2e8f0);
    font: 600 0.9375rem/1.25 var(--pagasa-sso-font, inherit);
    text-decoration: none;
    cursor: pointer;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease;
}
.pagasa-sso-btn:hover {
    background: var(--pagasa-sso-bg-hover, #26344a);
    border-color: var(--pagasa-sso-border-hover, #475569);
}
.pagasa-sso-btn:focus-visible {
    outline: 2px solid #0e5e8d;
    outline-offset: 2px;
}
.pagasa-sso-btn:disabled,
.pagasa-sso-btn[aria-disabled='true'] {
    opacity: 0.6;
    cursor: not-allowed;
}

/* The white tile is deliberate: the seal's four outer lobes are solid black
   on transparency and would disappear on a dark button. Point the url() at
   your own copy of the seal if you self-host it. */
.pagasa-sso-btn__logo {
    flex: none;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    background-color: #fff;
    background-image: url('https://sso2.pagasa.co/images/pagasa-logo-64.png');
    background-repeat: no-repeat;
    background-position: center;
    background-size: 22px 22px;
}

/* Light surfaces: add .pagasa-sso-btn--light */
.pagasa-sso-btn--light {
    --pagasa-sso-bg: #ffffff;
    --pagasa-sso-bg-hover: #f8fafc;
    --pagasa-sso-border: #d1d5db;
    --pagasa-sso-border-hover: #9ca3af;
    --pagasa-sso-fg: #1b1b18;
}
```

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

## Groups: permissions from AD

The SSO releases the user's AD groups that start with your app's group
prefix, and nothing else. With the prefix `PMIS-`, access is managed in AD by
adding people to `PMIS-Admin`, `PMIS-Approver`, and so on. Membership through
nested groups counts.

Gate routes with `sso.group`. Being in any one of the listed groups is
enough:

```php
Route::middleware(['sso.auth', 'sso.group:PMIS-Admin,PMIS-Approver'])->group(function () {
    Route::get('/approvals', ApprovalController::class);
});
```

| Situation | What happens |
| --- | --- |
| In one of the groups | Passes |
| Groups known, but in none of them | `403` |
| Groups not known in this session (restored from a remember-me cookie, or signed in before your app upgraded) | Sent through the SSO once to fetch them, then brought back |
| Groups not known, on a JSON request or with SSO disabled | `403` |

To check groups yourself:

```php
use Pagasa\Sso\Facades\Sso;

Sso::groups();                 // ['PMIS-Admin', 'PMIS-Viewer'], or null if not known
Sso::inGroup('PMIS-Admin');    // true if in any of the names given
```

```blade
@if (Sso::inGroup('PMIS-Admin'))
    <a href="/admin">Admin</a>
@endif
```

- Group names compare case-insensitively, because AD's do.
- Groups are recorded at sign-in and kept for the session. A change in AD
  reaches your app the next time the user signs in through the SSO, not
  in the middle of a session.
- The SSO checks the account in AD at every sign-in. Disabled, expired and
  deleted accounts get `invalid_grant`, even when their SSO session is old.

To keep roles in your own tables instead, map the groups when the user signs
in:

```php
use Illuminate\Support\Facades\Event;
use Pagasa\Sso\Events\SsoAuthenticated;

Event::listen(function (SsoAuthenticated $event) {
    $event->user->forceFill([
        'is_admin' => $event->result->inGroup('PMIS-Admin'),
    ])->save();
});
```

A custom `UserResolver` can read them from `$result->groups` too.

## Matching SSO users to local users

The SSO sends this profile:

```
guid, username, name, email, department, title, employee_number, ou
```

`ou` is the AD container the account sits in, e.g.
`OU=Hydromet,OU=Central Office`. Use it for display: OUs get reorganized, so
gate access on [groups](#groups-permissions-from-ad) instead.

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
| `invalid_grant` | The code expired (60s), was already used, or was missing; or the AD account is disabled, expired, or deleted |
| `unavailable` | The SSO could not reach Active Directory, or is down for maintenance (503). Try again shortly |
| `invalid_response` | The SSO answered with something unexpected (429, other 5xx) |
| `stale` | A step-up came back without a fresh verification |
| `no_account` | No local user matches, and provisioning is off |
| `user_mismatch` | The SSO returned a different person than the one signed in |

To fully control the response, bind your own
`Pagasa\Sso\Contracts\FailureResponder` in `sso.failure_responder`.

## Events, logging, logout

- `Pagasa\Sso\Events\SsoAuthenticated`: carries `$user`, `$result` (the full
  `SsoResult`, groups included), `$stepUp` and `$guard`. Listen to it to copy
  `department`/`title` elsewhere, map groups to roles, or write an audit
  trail.
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
  it again to replace it, for example to sign in and then step up. Pass
  groups as a claim: `SsoResponses::fake(['guid' => 'g-1'], ['groups' => ['PMIS-Admin']])`.
  By default there are none.
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
   `client_secret`, `code`) and gets back `{user, groups, auth_time, amr}`.
