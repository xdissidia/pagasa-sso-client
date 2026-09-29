<?php

namespace Pagasa\Sso\Contracts;

use Illuminate\Http\Request;
use Pagasa\Sso\SsoException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a failed sign-in into a response. Bind your own via
 * config('sso.failure_responder') to render an app-specific page.
 */
interface FailureResponder
{
    public function respond(Request $request, SsoException $exception): Response;
}
