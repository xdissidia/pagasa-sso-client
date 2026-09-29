# Changelog

## 1.1.0

Additive; apps on 1.0 need no changes. Against an SSO that does not send
groups yet, every user simply has none.

- AD groups: `SsoResult::$groups` and `inGroup()`, `Sso::groups()` and
  `Sso::inGroup()` for the signed-in user, and the `sso.group:{groups}` route
  middleware. Groups are recorded at sign-in and kept for the session; a
  session without them goes through the SSO once to fetch them.
- `SsoUser::$ou`: the AD container the account sits in. Also syncable
  through `sso.users.sync`.
- New failure reason `unavailable` for the SSO's `503` (Active Directory
  unreachable, or maintenance). It used to surface as `invalid_response`.
- `invalid_grant` also covers accounts disabled, expired, or deleted in AD,
  which the SSO now refuses at every sign-in.
- `SsoResponses` fakes include `ou` and `groups` (default none).

## 1.0.0

First release.

- `sso.auth` middleware, plus the `sso.login` and `sso.callback` routes, for the
  authorization-code flow. The redirect is Inertia-safe.
- Several sign-ins can be in flight at once (multiple tabs). Each `state` can be
  used only once and expires after 10 minutes.
- Token exchange with typed failure reasons, TLS verification (a CA bundle path is
  supported) and a timeout.
- `sso.fresh:{seconds}` step-up middleware. The callback checks `auth_time`
  server-side and refuses to swap in a different user.
- `GuidUserResolver`: matches on guid first, can adopt existing users through a
  fallback column, detects recycled values and can release unique columns.
- Extension points: `UserResolver`, `FailureResponder`, `SsoAuthenticated` and
  `SsoFailed` events.
- `sso::button` Blade partial, an optional users-table migration, and the
  `SsoResponses` testing helpers.
- Supports PHP 8.0+ and Laravel 8 – 13.
