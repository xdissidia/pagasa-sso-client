<?php

namespace Pagasa\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pagasa\Sso\SsoClient;
use Pagasa\Sso\SsoResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * `sso.group:{group}[,{group}...]` - require membership of any of the named
 * AD groups, as released by the SSO for this app.
 *
 * Groups are recorded at SSO sign-in and last for the session, so a change
 * in AD reaches the app at the user's next SSO sign-in, not mid-session.
 * A session with no groups recorded (restored from a remember-me cookie, or
 * started before the app used groups) goes through the SSO once to fetch
 * them. A user whose groups are known but don't match gets a 403.
 */
class RequireSsoGroup
{
    /** @var SsoClient */
    protected $sso;

    public function __construct(SsoClient $sso)
    {
        $this->sso = $sso;
    }

    public function handle(Request $request, Closure $next, string ...$groups): Response
    {
        if ($groups === []) {
            throw new InvalidArgumentException('sso.group needs at least one group name, e.g. sso.group:PMIS-Admin.');
        }

        $guard = $this->sso->activeGuard();
        $known = $this->sso->groups($request, $guard);

        if ($known === null) {
            abort_unless($this->sso->enabled(), 403, 'Group membership cannot be checked without SSO.');
            abort_if($request->expectsJson() && ! $request->header('X-Inertia'), 403, 'Group membership unknown; sign in again.');

            $request->session()->put(
                'url.intended',
                $request->isMethod('GET') ? $request->fullUrl() : url()->previous()
            );

            return $this->sso->redirect($request, false, $guard);
        }

        abort_unless(SsoResult::anyGroupMatches($known, $groups), 403, 'You do not have access to this page.');

        return $next($request);
    }
}
