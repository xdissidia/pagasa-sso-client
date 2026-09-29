<?php

namespace Pagasa\Sso\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A redirect off-site that also works for Inertia visits.
 *
 * Inertia follows redirects inside XHR, and an XHR cannot cross to the SSO's
 * origin. Inertia's protocol for this is a 409 carrying X-Inertia-Location,
 * which makes the client do a full page visit instead.
 */
class ExternalRedirect
{
    /**
     * @return RedirectResponse|Response
     */
    public static function to(Request $request, string $url)
    {
        if ($request->header('X-Inertia')) {
            return new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        return new RedirectResponse($url);
    }
}
