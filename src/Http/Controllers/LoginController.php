<?php

namespace Pagasa\Sso\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Pagasa\Sso\SsoClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET sso/login - start sign-in. Point a "Sign in with PAGASA SSO" button here.
 * ?prompt=login makes the SSO re-verify the user first (step-up).
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, SsoClient $sso): Response
    {
        abort_unless($sso->enabled(), 404);

        $stepUp = $request->query('prompt') === 'login';

        if (! $stepUp && Auth::guard($sso->guard())->check()) {
            return redirect()->intended($sso->home());
        }

        return $sso->redirect($request, $stepUp);
    }
}
