<?php

namespace Pagasa\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pagasa\Sso\SsoClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sso.fresh` / `sso.fresh:{seconds}` - require a recent SSO verification.
 *
 * If the signed-in user has not verified at the SSO within {seconds}
 * (default: config sso.max_auth_age), they are sent through a prompt=login
 * step-up and brought back.
 *
 * A POST cannot be replayed after the round-trip, so a stale POST returns to
 * the previous page and the user submits again. Put this middleware on the
 * GET route that shows the sensitive form too, so the step-up normally
 * happens before the form is filled in.
 */
class RequireFreshAuthentication
{
    /** @var SsoClient */
    protected $sso;

    public function __construct(SsoClient $sso)
    {
        $this->sso = $sso;
    }

    public function handle(Request $request, Closure $next, $seconds = null): Response
    {
        $maxAge = $seconds !== null && $seconds !== '' ? (int) $seconds : $this->sso->maxAuthAge();
        $guard = $this->sso->activeGuard();
        $authTime = $this->sso->authTime($request, $guard);

        if ($authTime !== null && time() - $authTime <= $maxAge) {
            return $next($request);
        }

        abort_unless($this->sso->enabled(), 403, 'Re-authentication through SSO is unavailable.');
        abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 403, 'Re-authentication required.');

        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous()
        );

        return $this->sso->stepUp($request, $guard);
    }
}
