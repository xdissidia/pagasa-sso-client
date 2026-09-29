<?php

namespace Pagasa\Sso\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Pagasa\Sso\SsoClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sso.auth` / `sso.auth:{guard}` - send guests through the SSO.
 *
 * Use it in place of `auth`. When SSO is disabled, and for JSON requests, it
 * throws AuthenticationException like `auth` does, so the app's own login
 * page or 401 takes over.
 */
class EnsureSsoAuthenticated
{
    /** @var SsoClient */
    protected $sso;

    public function __construct(SsoClient $sso)
    {
        $this->sso = $sso;
    }

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $guardName = $guard ?: $this->sso->guard();

        if (Auth::guard($guardName)->check()) {
            // Like `auth`: make $request->user() resolve through this guard.
            Auth::shouldUse($guardName);

            return $next($request);
        }

        if (! $this->sso->enabled() || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            throw new AuthenticationException('Unauthenticated.', [$guardName]);
        }

        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous()
        );

        return $this->sso->redirect($request, false, $guard ?: null);
    }
}
