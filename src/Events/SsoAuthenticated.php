<?php

namespace Pagasa\Sso\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pagasa\Sso\SsoResult;

/**
 * A user signed in (or completed a step-up) through the SSO.
 */
class SsoAuthenticated
{
    public function __construct(
        public Authenticatable $user,
        public SsoResult $result,
        public bool $stepUp,
        public string $guard
    ) {
    }
}
