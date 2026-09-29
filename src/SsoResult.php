<?php

namespace Pagasa\Sso;

/**
 * A successful token exchange: the profile plus the SSO's freshness claims.
 */
class SsoResult
{
    /**
     * @param  int|null  $authTime  Unix time of the user's last interactive verification
     *                              at the SSO; null for sessions restored from a
     *                              remember-me cookie
     * @param  string[]|null  $amr  methods used: pwd (AD password), otp (authenticator
     *                              code), rc (recovery code)
     */
    public function __construct(
        public SsoUser $user,
        public ?int $authTime,
        public ?array $amr
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  the decoded token response
     */
    public static function fromResponse(array $payload): self
    {
        $authTime = $payload['auth_time'] ?? null;
        $amr = $payload['amr'] ?? null;

        return new self(
            SsoUser::fromArray($payload['user']),
            is_numeric($authTime) ? (int) $authTime : null,
            is_array($amr) ? array_values(array_filter($amr, 'is_string')) : null
        );
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
