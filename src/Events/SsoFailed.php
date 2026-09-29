<?php

namespace Pagasa\Sso\Events;

use Illuminate\Http\Request;
use Pagasa\Sso\SsoException;

/**
 * A sign-in through the SSO was refused. $exception->reason() says why.
 */
class SsoFailed
{
    public function __construct(
        public SsoException $exception,
        public Request $request
    ) {
    }
}
