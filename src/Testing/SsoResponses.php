<?php

namespace Pagasa\Sso\Testing;

use ArrayObject;
use Illuminate\Support\Facades\Http;
use Pagasa\Sso\PendingStates;
use WeakMap;

/**
 * Fakes for testing an app's SSO sign-in without a live SSO.
 *
 *     SsoResponses::fake(['guid' => 'abc', 'username' => 'jdoe']);
 *
 *     $this->withSession(SsoResponses::pendingState('st'))
 *         ->get('/sso/callback?state=st&code=any')
 *         ->assertRedirect('/');
 */
class SsoResponses
{
    /** @var WeakMap<object, ArrayObject>|null */
    protected static $holders;

    /**
     * A profile as the SSO releases it.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    public static function user(array $overrides = []): array
    {
        return array_merge([
            'guid' => '8f14e45f-ceea-467a-9f3b-1c2d3e4f5a6b',
            'username' => 'jdelacruz',
            'name' => 'Juan Dela Cruz',
            'email' => 'jdelacruz@pagasa.dost.gov.ph',
            'department' => 'Weather Division',
            'title' => 'Weather Specialist I',
            'employee_number' => '012345',
        ], $overrides);
    }

    /**
     * A 200 token-endpoint body.
     *
     * @param  array<string, string|null>  $user  overrides for user()
     * @param  array<string, mixed>  $claims  overrides for auth_time (default now) and amr (default ['pwd'])
     * @return array<string, mixed>
     */
    public static function token(array $user = [], array $claims = []): array
    {
        return array_merge([
            'user' => self::user($user),
            'auth_time' => time(),
            'amr' => ['pwd'],
        ], $claims);
    }

    /**
     * Fake a successful exchange at the token endpoint. Calling it again in
     * the same test replaces the earlier fake (e.g. sign in, then step up).
     *
     * @param  array<string, string|null>  $user
     * @param  array<string, mixed>  $claims
     */
    public static function fake(array $user = [], array $claims = []): void
    {
        self::respondWith(self::token($user, $claims), 200);
    }

    /**
     * Fake a refused exchange, e.g. fakeError('invalid_client', 401).
     */
    public static function fakeError(string $error = 'invalid_grant', int $status = 400): void
    {
        self::respondWith([
            'error' => $error,
            'error_description' => 'Faked by SsoResponses.',
        ], $status);
    }

    /**
     * Session data for withSession() that makes $state a valid callback state.
     *
     * @return array<string, mixed>
     */
    public static function pendingState(string $state, bool $stepUp = false, ?string $guard = null): array
    {
        return [
            PendingStates::SESSION_KEY => [
                $state => ['step_up' => $stepUp, 'guard' => $guard, 'at' => time()],
            ],
        ];
    }

    /**
     * Http::fake() stubs stack and the first match wins, so a second fake for
     * the same URL would never be seen. Register one stub per HTTP factory
     * that reads from a holder, and just swap what the holder returns.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function respondWith(array $body, int $status): void
    {
        self::$holders = self::$holders ?? new WeakMap();

        if (! isset(self::$holders[Http::getFacadeRoot()])) {
            $holder = new ArrayObject();

            Http::fake([
                '*/api/sso/token' => function () use ($holder) {
                    return Http::response($holder['body'], $holder['status']);
                },
            ]);

            self::$holders[Http::getFacadeRoot()] = $holder;
        }

        $holder = self::$holders[Http::getFacadeRoot()];
        $holder['body'] = $body;
        $holder['status'] = $status;
    }
}
