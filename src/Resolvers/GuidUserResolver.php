<?php

namespace Pagasa\Sso\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Pagasa\Sso\SsoClient;
use Pagasa\Sso\Contracts\UserResolver;
use Pagasa\Sso\SsoResult;
use Pagasa\Sso\SsoUser;

/**
 * Default resolver, driven by config('sso.users').
 *
 *   1. guid match: that is the user, whatever else has changed.
 *   2. fallback column match (if configured): adopted only when the row has
 *      no guid of its own, then stamped with the guid so step 1 hits next time.
 *   3. fallback match already bound to a different guid: the value was
 *      recycled to someone new, so a new user is provisioned instead.
 *   4. otherwise a new user, if `create` is on.
 *
 * If the SSO sends no guid at all (accounts that predate the LDAP sync), a
 * fallback match is adopted as-is: without a guid a recycled value cannot be
 * told apart from its rightful owner, and refusing would lock out every user
 * the app already adopted.
 */
class GuidUserResolver implements UserResolver
{
    /** @var Repository */
    protected $config;

    /** @var SsoClient */
    protected $sso;

    public function __construct(Repository $config, SsoClient $sso)
    {
        $this->config = $config;
        $this->sso = $sso;
    }

    public function resolve(SsoResult $result, string $guard): ?Authenticatable
    {
        $profile = $result->user;
        $model = $this->model($guard);
        $guidColumn = (string) $this->option('guid_column', 'guid');

        if ($profile->guid !== null) {
            $user = $model->newQuery()->where($guidColumn, $profile->guid)->first();

            if ($user !== null) {
                return $this->save($user, $profile, false);
            }
        }

        [$fallbackColumn, $fallbackValue] = $this->fallback($profile);

        if ($fallbackValue !== null) {
            $candidate = $model->newQuery()->where($fallbackColumn, $fallbackValue)->first();

            if ($candidate !== null) {
                $candidateGuid = $candidate->getAttribute($guidColumn);

                if ($profile->guid === null || $candidateGuid === null || $candidateGuid === '') {
                    return $this->save($candidate, $profile, false);
                }

                $this->sso->log()->warning('sso: fallback value is bound to another guid; treating it as recycled', [
                    'column' => $fallbackColumn,
                    'existing_user_id' => $candidate->getKey(),
                    'existing_guid' => $candidateGuid,
                    'incoming_guid' => $profile->guid,
                ]);
            }
        }

        if (! $this->option('create', true)) {
            return null;
        }

        // Without either key there is nothing to find this user by next time,
        // so every sign-in would provision a duplicate.
        if ($profile->guid === null && $fallbackValue === null) {
            return null;
        }

        return $this->save($model->newInstance(), $profile, true);
    }

    /**
     * @return Model&Authenticatable
     */
    protected function save(Model $user, SsoUser $profile, bool $creating): Model
    {
        $attributes = [];

        foreach ((array) $this->option('sync', []) as $field => $column) {
            $value = $profile->get((string) $field);

            // A blank SSO value never erases local data.
            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        if ($profile->guid !== null) {
            $attributes[(string) $this->option('guid_column', 'guid')] = $profile->guid;
        }

        [$fallbackColumn, $fallbackValue] = $this->fallback($profile);

        if ($creating && $fallbackValue !== null) {
            $attributes[$fallbackColumn] = $fallbackValue;
        }

        $passwordColumn = $this->option('password_column', 'password');

        if ($creating && $passwordColumn) {
            $attributes[$passwordColumn] = Hash::make(Str::random(64));
        }

        $this->releaseHeldValues($user, $attributes);

        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * Clear configured unique values still held by other rows, so a value
     * recycled in AD does not fail the write on a unique index.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function releaseHeldValues(Model $user, array $attributes): void
    {
        foreach ((array) $this->option('release', []) as $column) {
            if (! isset($attributes[$column])) {
                continue;
            }

            $query = $user->newQuery()->where($column, $attributes[$column]);

            if ($user->exists) {
                $query->whereKeyNot($user->getKey());
            }

            $held = $query->pluck($user->getKeyName())->all();

            if ($held === []) {
                continue;
            }

            $this->sso->log()->warning("sso: releasing {$column} held by another user", [
                'column' => $column,
                'released_user_ids' => $held,
            ]);

            $user->newQuery()->whereKey($held)->update([$column => null]);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null} [column, value]
     */
    protected function fallback(SsoUser $profile): array
    {
        $fallback = $this->option('fallback');

        if (! is_array($fallback) || empty($fallback['profile']) || empty($fallback['column'])) {
            return [null, null];
        }

        return [(string) $fallback['column'], $profile->get((string) $fallback['profile'])];
    }

    protected function model(string $guard): Model
    {
        $class = $this->option('model');

        if (! $class) {
            $provider = $this->config->get("auth.guards.{$guard}.provider");
            $class = $this->config->get("auth.providers.{$provider}.model");
        }

        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            throw new LogicException(
                "GuidUserResolver needs an Eloquent user model: set sso.users.model, or give guard [{$guard}] an eloquent provider."
            );
        }

        return new $class();
    }

    protected function option(string $key, $default = null)
    {
        return $this->config->get("sso.users.{$key}", $default);
    }
}
