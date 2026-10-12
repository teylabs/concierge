<?php

namespace Tey\Concierge\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use SensitiveParameter;
use Tey\Concierge\Registration\Registration;

/**
 * Validates the email a person registers with against concierge.registration.
 * The invitation token is read from the validated data, then from the current
 * request, under the "invitation" key, so a token on the form is checked on
 * the request that creates the account. When the request carries none, the
 * token remembered from the register link (?invitation=TOKEN) is used. Pass
 * one explicitly to read it from somewhere else.
 *
 *     'email' => ['required', 'email', 'unique:users', new MayRegister],
 */
class MayRegister implements DataAwareRule, ValidationRule
{
    public const string TOKEN_INPUT = 'invitation';

    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(#[SensitiveParameter] protected ?string $token = null) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('Registration is closed.'));

            return;
        }

        $decision = Registration::decide($value, $this->token());

        if ($decision->refused()) {
            $fail((string) $decision->message());
        }
    }

    protected function token(): ?string
    {
        foreach ([$this->token, $this->data[self::TOKEN_INPUT] ?? null, request()->input(self::TOKEN_INPUT)] as $token) {
            if (is_string($token) && trim($token) !== '') {
                return $token;
            }
        }

        return Registration::remembered();
    }
}
