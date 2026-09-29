<?php

namespace Pagasa\Sso\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Pagasa\Sso\Contracts\FailureResponder;
use Pagasa\Sso\Contracts\UserResolver;
use Pagasa\Sso\Events\SsoAuthenticated;
use Pagasa\Sso\Events\SsoFailed;
use Pagasa\Sso\SsoClient;
use Pagasa\Sso\SsoException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET sso/callback?code&state - where the SSO sends the browser back.
 */
class CallbackController extends Controller
{
    public function __invoke(Request $request, SsoClient $sso, UserResolver $resolver, FailureResponder $responder): Response
    {
        $pending = $sso->states($request)->pull($request->query('state'));
        $guardName = empty($pending['guard']) ? $sso->guard() : (string) $pending['guard'];
        $guard = Auth::guard($guardName);

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException("SSO needs a session-based guard; [{$guardName}] is not one.");
        }

        try {
            if ($pending === null) {
                // Back button or a reload of the callback URL after a sign-in
                // that already succeeded: nothing to do.
                if ($guard->check()) {
                    return redirect()->intended($sso->home());
                }

                throw SsoException::invalidState();
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw SsoException::invalidGrant(['error' => 'missing_code']);
            }

            $result = $sso->exchange($code);
            $stepUp = (bool) $pending['step_up'];

            // A step-up is only as good as its freshness claim. Never trust
            // the redirect alone.
            if ($stepUp && ! $result->isFresh($sso->maxAuthAge())) {
                throw SsoException::stale($result->ageInSeconds(), $sso->maxAuthAge());
            }

            $user = $resolver->resolve($result, $guardName);

            if ($user === null) {
                throw SsoException::noAccount([
                    'guid' => $result->user->guid,
                    'username' => $result->user->username,
                ]);
            }

            // Someone is already signed in here and the SSO came back with a
            // different person: refuse rather than silently switch accounts.
            $current = $guard->user();

            if ($current !== null && (string) $current->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
                throw SsoException::userMismatch([
                    'current_user_id' => $current->getAuthIdentifier(),
                    'sso_user_id' => $user->getAuthIdentifier(),
                ]);
            }
        } catch (SsoException $e) {
            event(new SsoFailed($e, $request));

            $sso->log()->warning('sso: sign-in failed: '.$e->reason(), $e->context() + [
                'ip' => $request->ip(),
            ]);

            return $responder->respond($request, $e);
        }

        $guard->login($user, $sso->remember());
        $request->session()->regenerate();
        $sso->markVerified($request, $user, $guardName, $result);

        event(new SsoAuthenticated($user, $result, $stepUp, $guardName));

        $sso->log()->info('sso: signed in', [
            'user_id' => $user->getAuthIdentifier(),
            'guid' => $result->user->guid,
            'step_up' => $stepUp,
            'amr' => $result->amr,
        ]);

        return redirect()->intended($sso->home());
    }
}
