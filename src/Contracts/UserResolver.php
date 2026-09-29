<?php

namespace Pagasa\Sso\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Pagasa\Sso\SsoResult;

/**
 * Maps a verified SSO profile onto a local user.
 *
 * Bind your own implementation via config('sso.resolver') when the default
 * GuidUserResolver does not fit, e.g. users are matched through another
 * table, or accounts are provisioned by HR and never auto-created.
 */
interface UserResolver
{
    /**
     * @param  string  $guard  the guard the user is about to be signed in with
     * @return Authenticatable|null  null refuses the sign-in (`no_account`)
     */
    public function resolve(SsoResult $result, string $guard): ?Authenticatable;
}
