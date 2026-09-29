<?php

namespace Pagasa\Sso;

use Illuminate\Contracts\Session\Session;

/**
 * The `state` values this session has sent to the SSO and not yet seen back.
 *
 * A map rather than a single value: a user with several tabs open when their
 * session lapses sends several round-trips at once, and with a single slot
 * every tab but the last would fail the state check.
 */
class PendingStates
{
    public const SESSION_KEY = 'sso.pending';

    /** How many round-trips may be in flight at once. */
    public const MAX = 5;

    /** Seconds a state stays valid; well beyond the SSO's own login + 2FA time. */
    public const TTL = 600;

    /** @var Session */
    protected $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Remember a state for this session.
     */
    public function put(string $state, bool $stepUp, ?string $guard = null, ?int $now = null): void
    {
        $now = $now ?? time();

        $pending = $this->live($now);
        $pending[$state] = ['step_up' => $stepUp, 'guard' => $guard, 'at' => $now];

        // Oldest first, so trimming from the front drops the stalest.
        uasort($pending, function ($a, $b) {
            return $a['at'] <=> $b['at'];
        });

        $this->session->put(self::SESSION_KEY, array_slice($pending, -self::MAX, null, true));
    }

    /**
     * Consume a state. Returns its metadata, or null if this session never
     * issued it, it expired, or it was already used.
     *
     * @return array{step_up: bool, guard: string|null, at: int}|null
     */
    public function pull($state, ?int $now = null): ?array
    {
        $pending = $this->live($now ?? time());

        if (! is_string($state) || $state === '') {
            $this->session->put(self::SESSION_KEY, $pending);

            return null;
        }

        $match = null;

        foreach (array_keys($pending) as $candidate) {
            if (hash_equals((string) $candidate, $state)) {
                $match = $candidate;
            }
        }

        if ($match === null) {
            $this->session->put(self::SESSION_KEY, $pending);

            return null;
        }

        $entry = $pending[$match];
        unset($pending[$match]);
        $this->session->put(self::SESSION_KEY, $pending);

        return $entry;
    }

    /**
     * @return array<string, array{step_up: bool, guard: string|null, at: int}>
     */
    protected function live(int $now): array
    {
        $pending = $this->session->get(self::SESSION_KEY, []);

        if (! is_array($pending)) {
            return [];
        }

        return array_filter($pending, function ($entry) use ($now) {
            return is_array($entry)
                && isset($entry['at'])
                && $now - (int) $entry['at'] <= self::TTL;
        });
    }
}
