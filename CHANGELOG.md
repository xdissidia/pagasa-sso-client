# Changelog

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
