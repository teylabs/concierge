<?php

namespace Tey\Concierge\Registration\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Fortify;
use Tey\Concierge\Registration\Registration;
use Tey\Concierge\Rules\MayRegister;
use Tey\Concierge\Support\InvitationRules;
use Tey\Concierge\Support\VerifiedEmail;

/**
 * Wraps the app's CreatesNewUsers (usually App\Actions\Fortify\CreateNewUser)
 * so the registration gate runs on every request Fortify turns into an
 * account, without the app editing its own action. A refusal is a validation
 * error on the email field, like any other. In closed mode the request is a
 * 404, the same as the register page. The token remembered from the register
 * link is cleared once the account exists.
 */
class GatedUserCreator implements CreatesNewUsers
{
    public function __construct(protected CreatesNewUsers $creator) {}

    /**
     * @param  array<string, mixed>  $input
     * @return Authenticatable
     */
    public function create(array $input)
    {
        abort_if(Registration::closed(), 404);

        Validator::make($input, [
            Fortify::email() => ['required', 'string', new MayRegister],
        ])->validate();

        // Read before the creator runs: an invitation it accepts on the way is no longer pending.
        // The same token MayRegister admitted: on the form, then remembered from the register link.
        $token = collect([$input[MayRegister::TOKEN_INPUT] ?? null, request()->input(MayRegister::TOKEN_INPUT), Registration::remembered()])
            ->first(fn ($candidate) => is_string($candidate) && trim($candidate) !== '');
        $token = is_string($token) ? trim($token) : null;
        $invited = $token !== null && Registration::invitationRefusal((string) ($input[Fortify::email()] ?? ''), $token) === null;

        $user = $this->creator->create($input);

        // Registering through the emailed invitation proves the address, so the account starts verified and Fortify's
        // Registered → SendEmailVerificationNotification (fired after this returns) sends nothing.
        if ($invited && hash_equals(InvitationRules::normalizeEmail((string) ($input[Fortify::email()] ?? '')), InvitationRules::normalizeEmail((string) ($user->{Fortify::email()} ?? '')))) {
            VerifiedEmail::mark($user);
        }

        Registration::forget();

        return $user;
    }
}
