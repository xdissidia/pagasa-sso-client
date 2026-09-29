<?php

namespace Pagasa\Sso\Facades;

use Illuminate\Support\Facades\Facade;
use Pagasa\Sso\SsoClient;

/**
 * @method static bool enabled()
 * @method static string redirectUri()
 * @method static string authorizeUrl(string $state, bool $stepUp = false)
 * @method static \Symfony\Component\HttpFoundation\Response redirect(\Illuminate\Http\Request $request, bool $stepUp = false, ?string $guard = null)
 * @method static \Symfony\Component\HttpFoundation\Response stepUp(\Illuminate\Http\Request $request, ?string $guard = null)
 * @method static \Pagasa\Sso\SsoResult exchange(string $code)
 * @method static int|null authTime(?\Illuminate\Http\Request $request = null)
 * @method static string[]|null amr(?\Illuminate\Http\Request $request = null)
 * @method static string[]|null groups(?\Illuminate\Http\Request $request = null)
 * @method static bool inGroup(string ...$groups)
 *
 * @see SsoClient
 */
class Sso extends Facade
{
    protected static function getFacadeAccessor()
    {
        return SsoClient::class;
    }
}
