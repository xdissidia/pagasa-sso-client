<?php

namespace Pagasa\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pagasa\Sso\Http\ExternalRedirect;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client for the PAGASA SSO authorization-code flow.
 *
 *   1. redirect()  sends the browser to {SSO}/sso/authorize with a fresh state
 *   2. the SSO     sends it back to sso.callback with ?code&state
 *   3. exchange()  swaps the one-time code for the profile, server to server
 */
class SsoClient
{
    /** Session key holding the last completed sign-in's freshness claims. */
    public const VERIFIED_KEY = 'sso.verified';

    /** @var Repository */
    protected $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    /**
     * Whether SSO is switched on and fully configured.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('sso.enabled', true)
            && (string) $this->config->get('sso.url') !== ''
            && (string) $this->config->get('sso.client_id') !== ''
            && (string) $this->config->get('sso.client_secret') !== '';
    }

    /**
     * The callback URL. Must equal the redirect_uri registered on the SSO exactly.
     */
    public function redirectUri(): string
    {
        return (string) ($this->config->get('sso.redirect_uri') ?: route('sso.callback'));
    }

    /**
     * Where to send the browser to start sign-in.
     *
     * @param  bool  $stepUp  ask the SSO to re-verify the user (prompt=login)
     */
    public function authorizeUrl(string $state, bool $stepUp = false): string
    {
        $query = [
            'client_id' => $this->config->get('sso.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ];

        if ($stepUp) {
            $query['prompt'] = 'login';
        }

        return $this->endpoint('/sso/authorize').'?'.http_build_query($query);
    }

    /**
     * Start sign-in: remember a fresh state in the session and redirect to the SSO.
     * Safe for Inertia requests.
     *
     * @param  string|null  $guard  guard to sign the user in with on return
     */
    public function redirect(Request $request, bool $stepUp = false, ?string $guard = null): Response
    {
        $state = Str::random(40);

        $this->states($request)->put($state, $stepUp, $guard);

        return ExternalRedirect::to($request, $this->authorizeUrl($state, $stepUp));
    }

    /**
     * Start a step-up: the SSO re-verifies the user before sending them back.
     */
    public function stepUp(Request $request, ?string $guard = null): Response
    {
        return $this->redirect($request, true, $guard);
    }

    /**
     * Exchange a one-time authorization code for the user's profile.
     *
     * @throws SsoException
     */
    public function exchange(string $code): SsoResult
    {
        $url = $this->endpoint('/api/sso/token');

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeout())
                ->withOptions(['verify' => $this->verify()])
                ->post($url, [
                    'client_id' => $this->config->get('sso.client_id'),
                    'client_secret' => $this->config->get('sso.client_secret'),
                    'code' => $code,
                ]);
        } catch (ConnectionException $e) {
            // DNS failure, refused connection, timeout, and TLS verification
            // failure all land here. Against an internal CA, point SSO_VERIFY_SSL
            // at the CA bundle rather than disabling verification: this request
            // carries the client secret.
            throw SsoException::unreachable($url, $e);
        }

        $context = [
            'status' => $response->status(),
            'error' => $response->json('error'),
            'error_description' => $response->json('error_description'),
        ];

        if ($response->status() === 401) {
            throw SsoException::invalidClient($context);
        }

        if ($response->status() === 400) {
            throw SsoException::invalidGrant($context);
        }

        if ($response->status() === 503) {
            throw SsoException::unavailable($context);
        }

        if (! $response->successful()) {
            throw SsoException::invalidResponse($context);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['user'] ?? null)) {
            throw SsoException::invalidResponse([
                'status' => $response->status(),
                'keys' => is_array($payload) ? array_keys($payload) : null,
            ]);
        }

        return SsoResult::fromResponse($payload);
    }

    /**
     * Record a completed sign-in's freshness claims and groups against the
     * user it signed in.
     */
    public function markVerified(Request $request, Authenticatable $user, string $guard, SsoResult $result): void
    {
        $request->session()->put(self::VERIFIED_KEY, [
            'guard' => $guard,
            'user' => $user->getAuthIdentifier(),
            'auth_time' => $result->authTime,
            'amr' => $result->amr,
            'groups' => $result->groups,
        ]);
    }

    /**
     * Unix time the current user last verified interactively at the SSO, or
     * null if unknown (not signed in through SSO, or restored from a
     * remember-me cookie at the SSO).
     */
    public function authTime(?Request $request = null): ?int
    {
        $verified = $this->verified($request);

        return isset($verified['auth_time']) ? (int) $verified['auth_time'] : null;
    }

    /**
     * Methods the current user last verified with at the SSO (pwd, otp, rc).
     *
     * @return string[]|null
     */
    public function amr(?Request $request = null): ?array
    {
        $verified = $this->verified($request);

        return isset($verified['amr']) && is_array($verified['amr']) ? $verified['amr'] : null;
    }

    /**
     * The current user's AD groups for this app, as released at their last
     * SSO sign-in in this session. Null if unknown: the session was not
     * started through the SSO (e.g. restored from this app's remember-me
     * cookie), or predates group support.
     *
     * @return string[]|null
     */
    public function groups(?Request $request = null): ?array
    {
        $verified = $this->verified($request);

        return isset($verified['groups']) && is_array($verified['groups']) ? $verified['groups'] : null;
    }

    /**
     * Whether the current user is in any of the given AD groups. False while
     * their groups are unknown.
     */
    public function inGroup(string ...$groups): bool
    {
        $known = $this->groups();

        return $known !== null && SsoResult::anyGroupMatches($known, $groups);
    }

    public function states(Request $request): PendingStates
    {
        return new PendingStates($request->session());
    }

    /**
     * The configured guard name, or the app's default guard.
     */
    public function guard(): string
    {
        return (string) ($this->config->get('sso.guard') ?: Auth::getDefaultDriver());
    }

    public function home(): string
    {
        return (string) $this->config->get('sso.home', '/');
    }

    public function remember(): bool
    {
        return (bool) $this->config->get('sso.remember', false);
    }

    public function maxAuthAge(): int
    {
        return (int) $this->config->get('sso.max_auth_age', 120);
    }

    public function log(): LoggerInterface
    {
        return Log::channel($this->config->get('sso.log_channel'));
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) $this->config->get('sso.url'), '/').$path;
    }

    protected function timeout(): int
    {
        return max(1, (int) $this->config->get('sso.timeout', 10));
    }

    /**
     * @return bool|string true/false, or a path to a CA bundle
     */
    protected function verify()
    {
        $verify = $this->config->get('sso.verify', true);

        if (is_string($verify)) {
            $flag = filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $flag === null ? $verify : $flag;
        }

        return $verify === null ? true : (bool) $verify;
    }

    /**
     * The stored claims, but only while the user they were recorded for is
     * still the one signed in - never another user's leftovers.
     *
     * @return array<string, mixed>|null
     */
    protected function verified(?Request $request): ?array
    {
        $request = $request ?: request();

        if (! $request->hasSession()) {
            return null;
        }

        $verified = $request->session()->get(self::VERIFIED_KEY);

        if (! is_array($verified) || ! isset($verified['guard'], $verified['user'])) {
            return null;
        }

        $user = Auth::guard($verified['guard'])->user();

        if ($user === null || (string) $user->getAuthIdentifier() !== (string) $verified['user']) {
            return null;
        }

        return $verified;
    }
}
