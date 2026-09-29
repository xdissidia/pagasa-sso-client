<?php

namespace Pagasa\Sso;

use RuntimeException;
use Throwable;

/**
 * A failed sign-in. reason() is a stable slug to branch on or flash to the user.
 */
class SsoException extends RuntimeException
{
    /** The callback's state did not match one this session issued. */
    public const STATE = 'state';

    /** The token endpoint could not be reached (DNS, refused, timeout, TLS). */
    public const UNREACHABLE = 'unreachable';

    /** The SSO rejected client_id / client_secret. */
    public const INVALID_CLIENT = 'invalid_client';

    /** The code was missing, invalid, expired, already used, or for another client. */
    public const INVALID_GRANT = 'invalid_grant';

    /** The token endpoint answered, but not with the expected shape. */
    public const INVALID_RESPONSE = 'invalid_response';

    /** A step-up came back with a verification older than max_auth_age. */
    public const STALE = 'stale';

    /** The resolver found no local user for this profile. */
    public const NO_ACCOUNT = 'no_account';

    /** A step-up signed in a different person than the one already signed in. */
    public const USER_MISMATCH = 'user_mismatch';

    /** @var array<string, mixed> */
    protected $context;

    /** @var string */
    protected $reason;

    /**
     * @param  array<string, mixed>  $context  safe to log; never holds secrets
     */
    public function __construct(string $reason, string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->reason = $reason;
        $this->context = $context;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public static function invalidState(): self
    {
        return new self(self::STATE, 'Invalid SSO state.');
    }

    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self(self::UNREACHABLE, 'Could not reach the SSO token endpoint.', [
            'url' => $url,
            'message' => $previous->getMessage(),
        ], $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidClient(array $context = []): self
    {
        return new self(self::INVALID_CLIENT, 'The SSO rejected this client\'s credentials.', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidGrant(array $context = []): self
    {
        return new self(self::INVALID_GRANT, 'The SSO authorization code is invalid, expired, or already used.', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidResponse(array $context = []): self
    {
        return new self(self::INVALID_RESPONSE, 'The SSO token response was not understood.', $context);
    }

    public static function stale(?int $ageInSeconds, int $maxAge): self
    {
        return new self(self::STALE, 'The SSO re-authentication is too old.', [
            'age_seconds' => $ageInSeconds,
            'max_auth_age' => $maxAge,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function noAccount(array $context = []): self
    {
        return new self(self::NO_ACCOUNT, 'No account in this application matches the SSO user.', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function userMismatch(array $context = []): self
    {
        return new self(self::USER_MISMATCH, 'The SSO re-authenticated a different user than the one signed in.', $context);
    }
}
