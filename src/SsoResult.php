<?php

namespace Pagasa\Sso;

use Illuminate\Support\Str;

/**
 * A successful token exchange: the profile, the user's AD groups for this
 * app, and the SSO's freshness claims.
 */
class SsoResult
{
    /**
     * @param  int|null  $authTime  Unix time of the user's last interactive verification
     *                              at the SSO; null for sessions restored from a
     *                              remember-me cookie
     * @param  string[]|null  $amr  methods used: pwd (AD password), otp (authenticator
     *                              code), rc (recovery code)
     * @param  string[]  $groups  the user's AD groups, nested memberships included, that
     *                            start with the group prefix this app is registered with
     *                            on the SSO. Empty when the app has no prefix.
     */
    public function __construct(
        public SsoUser $user,
        public ?int $authTime,
        public ?array $amr,
        public array $groups = []
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  the decoded token response
     */
    public static function fromResponse(array $payload): self
    {
        $authTime = $payload['auth_time'] ?? null;
        $amr = $payload['amr'] ?? null;
        $groups = $payload['groups'] ?? null;

        return new self(
            SsoUser::fromArray($payload['user']),
            is_numeric($authTime) ? (int) $authTime : null,
            is_array($amr) ? array_values(array_filter($amr, 'is_string')) : null,
            // Absent from SSOs that predate group release: no groups.
            is_array($groups) ? array_values(array_filter($groups, 'is_string')) : []
        );
    }

    /**
     * Whether the user is in any of the given AD groups.
     */
    public function inGroup(string ...$groups): bool
    {
        return self::anyGroupMatches($this->groups, $groups);
    }

    /**
     * Whether $groups holds any of $wanted. AD group names are
     * case-insensitive, so the comparison is too.
     *
     * @param  string[]  $groups
     * @param  string[]  $wanted
     */
    public static function anyGroupMatches(array $groups, array $wanted): bool
    {
        $groups = array_map([Str::class, 'lower'], $groups);

        foreach ($wanted as $group) {
            if (in_array(Str::lower($group), $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds since the last interactive verification, or null if unknown.
     */
    public function ageInSeconds(?int $now = null): ?int
    {
        if ($this->authTime === null) {
            return null;
        }

        return ($now ?? time()) - $this->authTime;
    }

    /**
     * Whether the user verified interactively within the last $seconds.
     */
    public function isFresh(int $seconds, ?int $now = null): bool
    {
        $age = $this->ageInSeconds($now);

        return $age !== null && $age <= $seconds;
    }

    /**
     * Whether the verification used a second factor (authenticator or recovery code).
     */
    public function usedTwoFactor(): bool
    {
        return (bool) array_intersect($this->amr ?? [], ['otp', 'rc']);
    }
}
