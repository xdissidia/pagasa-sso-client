<?php

namespace Pagasa\Sso\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pagasa\Sso\Contracts\FailureResponder;
use Pagasa\Sso\SsoException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects to config('sso.failure_redirect') with the reason flashed as
 * `sso_error`, or aborts with a 403 when no failure page is configured.
 */
class DefaultFailureResponder implements FailureResponder
{
    /** @var Repository */
    protected $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function respond(Request $request, SsoException $exception): Response
    {
        $target = (string) $this->config->get('sso.failure_redirect');

        if ($target === '') {
            abort(403, $exception->getMessage());
        }

        $url = Route::has($target) ? route($target) : url($target);

        return redirect()->to($url)->with('sso_error', $exception->reason());
    }
}
