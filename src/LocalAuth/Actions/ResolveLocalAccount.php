<?php

namespace Tey\Concierge\LocalAuth\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tey\Concierge\LocalAuth\Exceptions\LocalLoginRefused;

/**
 * Find the configured account, or create it the first time.
 *
 * - The identity is the configured one, never anything the browser sent.
 * - An existing account is returned exactly as it is: no save, no password,
 *   name, verification or role change.
 * - A missing one is created with a random password nobody knows (hashed
 *   normally), a sensible name, a verified address if the table has the column,
 *   and the configured extra attributes; those can never override the identity
 *   or the password. No roles are given.
 * - Two first clicks racing is safe: the loser hits the unique index, rolls back
 *   its insert, re-reads the winner's row and carries on.
 * - A soft-deleted match is refused, not restored and not duplicated.
 * - Only the Eloquent user provider is supported; anything else fails closed.
 */
final class ResolveLocalAccount
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(string $guard, string $identity, string $value, ?string $name, array $attributes): Authenticatable
    {
        $model = $this->model($guard);

        $existing = $this->find($model, $identity, $value);

        if ($existing !== null) {
            return $this->usable($existing, $value);
        }

        $account = new $model;
        $account->forceFill([
            ...$this->defaults($account, $value, $name),
            ...Arr::except($attributes, [$identity, 'password']),
            $identity => $value,
            'password' => Hash::make(Str::random(48)),
        ]);

        // The insert runs in its own transaction on the model's connection. Inside an
        // outer transaction that is a savepoint, so a losing race rolls back to it and
        // leaves the outer transaction usable: PostgreSQL aborts the whole transaction
        // on a unique violation otherwise, and the re-read below would fail. The catch
        // is outside, after that rollback.
        try {
            $account->getConnection()->transaction(fn () => $account->save());
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->find($model, $identity, $value) ?? throw $exception;

            return $this->usable($winner, $value);
        }

        return $account;
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    private function model(string $guard): string
    {
        $provider = config("auth.guards.{$guard}.provider");
        $driver = $provider !== null ? config("auth.providers.{$provider}.driver") : null;
        $model = $provider !== null ? config("auth.providers.{$provider}.model") : null;

        if ($driver !== 'eloquent' || ! is_string($model) || ! is_subclass_of($model, Model::class) || ! is_subclass_of($model, Authenticatable::class)) {
            throw new LocalLoginRefused("Local login supports the Eloquent user provider only; guard [{$guard}] does not use one.");
        }

        return $model;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function find(string $model, string $identity, string $value): ?Model
    {
        /** @var Builder<Model> $query */
        $query = $model::query();

        // Only a model that actually soft-deletes has trashed rows to see.
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->where($identity, $value)->first();
    }

    private function usable(Model $account, string $value): Authenticatable
    {
        if (method_exists($account, 'trashed') && $account->trashed()) {
            throw new LocalLoginRefused("The account [{$value}] exists but is deleted. Restore or remove it, or configure a different local account.");
        }

        if (! $account instanceof Authenticatable) {
            throw new LocalLoginRefused('The configured user model is not an authenticatable.');
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(Model $account, string $value, ?string $name): array
    {
        $defaults = [];

        // Only columns the table actually has, asked of the model's own connection
        // (not the default one): a leaner user model works too.
        $schema = $account->getConnection()->getSchemaBuilder();

        if ($schema->hasColumn($account->getTable(), 'name')) {
            $defaults['name'] = $name ?: Str::headline(Str::before($value, '@'));
        }

        if ($schema->hasColumn($account->getTable(), 'email_verified_at')) {
            $defaults['email_verified_at'] = now();
        }

        return $defaults;
    }
}
