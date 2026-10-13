<?php

namespace Tey\Concierge\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserUpdated;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\ManagedAccount;

/**
 * Edits another user's name and email in app-wide mode: what UserPage submits.
 * The same rules as removal apply through the AccountPolicy: not yourself (the
 * app's own profile page covers that), not someone of equal or higher rank, and
 * the AccountGuard's veto. A null argument leaves that field alone.
 *
 * The fields are written to the model's `name` and `email` attributes, which
 * is what InteractsWithMemberships reads; Member has no column mapping.
 * Email verification is the app's business: email_verified_at is not touched
 * and nothing is sent. Listen to UserUpdated to react to a changed email.
 */
class UpdateUser
{
    use Runnable;

    public const NAME_LENGTH = 255;

    public const EMAIL_LENGTH = 255;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, Member $user, ?string $name = null, ?string $email = null): Member
    {
        ConciergeModels::assertAppWide("UpdateUser is app-wide only; update the user model directly, or set 'mode' => 'app' in config/concierge.php");

        if (! $user instanceof Model) {
            throw new UnsupportedConfiguration('UpdateUser needs an Eloquent user model; pass an instance of the model set as concierge.models.user.');
        }

        return ManagedAccount::mutate($actor, $user, function (Account $actorAccount, ?Account $account) use ($actor, $user, $name, $email) {
            $this->policy->authorizeManagement($actor, $user)->authorize();

            $new = $this->validated($user, $name, $email);
            $changes = [];

            foreach ($new as $field => $value) {
                $old = $user->getAttribute($field);

                if ($old !== $value) {
                    $changes[$field] = ['old' => $old === null ? null : (string) $old, 'new' => $value];
                }
            }

            if ($changes === []) {
                return $user;
            }

            try {
                $user->forceFill(array_map(fn (array $change) => $change['new'], $changes))->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => __('That email is already in use.')]);
            }

            UserUpdated::dispatch((int) $user->getKey(), $actor->getKey(), $changes);

            return $user;
        });
    }

    /** @return array<string, string> the submitted fields, trimmed */
    protected function validated(Model $user, ?string $name, ?string $email): array
    {
        $fields = [];

        if ($name !== null) {
            $name = trim($name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('Enter a name.')]);
            }

            if (mb_strlen($name) > self::NAME_LENGTH) {
                throw ValidationException::withMessages(['name' => __('The name may not be longer than :max characters.', ['max' => self::NAME_LENGTH])]);
            }

            $fields['name'] = $name;
        }

        if ($email !== null) {
            $email = trim($email);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > self::EMAIL_LENGTH) {
                throw ValidationException::withMessages(['email' => __('Enter a valid email address.')]);
            }

            $taken = ConciergeModels::user()::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => __('That email is already in use.')]);
            }

            $fields['email'] = $email;
        }

        return $fields;
    }
}
