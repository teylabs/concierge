<?php

namespace Tey\Concierge\Registration\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Fortify;
use Tey\Concierge\Registration\Registration;
use Tey\Concierge\Rules\MayRegister;

/**
 * Wraps the app's CreatesNewUsers (usually App\Actions\Fortify\CreateNewUser)
 * so the registration gate runs on every request Fortify turns into an
 * account, without the app editing its own action. A refusal is a validation
 * error on the email field, like any other. In closed mode the request is a
 * 404, the same as the register page.
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

        return $this->creator->create($input);
    }
}
