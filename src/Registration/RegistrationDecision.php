<?php

namespace Tey\Concierge\Registration;

final readonly class RegistrationDecision
{
    private function __construct(
        public bool $allowed,
        public ?RegistrationRefusal $refusal,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function refuse(RegistrationRefusal $refusal): self
    {
        return new self(false, $refusal);
    }

    public function refused(): bool
    {
        return ! $this->allowed;
    }

    /** The sentence to show the person; null when registration is allowed. */
    public function message(): ?string
    {
        return $this->refusal?->message();
    }
}
