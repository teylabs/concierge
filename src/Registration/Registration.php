<?php

namespace Tey\Concierge\Registration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Enums\InvitationStatus;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\TenantInvitation;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\InvitationRules;

/**
 * The registration gate: one decision, from concierge.registration, about
 * whether an email may create an account. It fails closed whenever the
 * configuration cannot be trusted, and an invitation token is only ever
 * honoured for the email it was sent to.
 */
final class Registration
{
    /** Session key for the invitation token from the register link. */
    public const string REMEMBERED_TOKEN = 'concierge.registration.invitation';

    public static function mode(): RegistrationMode
    {
        return RegistrationMode::configured();
    }

    /**
     * Allowed domains, lower-cased and trimmed, from a comma-separated string
     * or an array. A leading "@" is tolerated. Empty entries are dropped.
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        $configured = config('concierge.registration.domains', []);

        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        $domains = [];

        foreach ($values as $value) {
            $domain = strtolower(ltrim(trim((string) $value), '@'));

            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Remember the token a register link carried, so the kit's unchanged form
     * can post without it. It is only a lookup key: decide() still checks it
     * on the request that creates the account.
     */
    public static function remember(Request $request, #[SensitiveParameter] mixed $token): void
    {
        if ($request->hasSession() && is_string($token) && trim($token) !== '') {
            $request->session()->put(self::REMEMBERED_TOKEN, trim($token));
        }
    }

    public static function remembered(?Request $request = null): ?string
    {
        $request ??= request();

        $token = $request->hasSession() ? $request->session()->get(self::REMEMBERED_TOKEN) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function forget(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->forget(self::REMEMBERED_TOKEN);
        }
    }

    /** True only in open mode: the app's sign-in page can decide whether to link to registration. */
    public static function open(): bool
    {
        return self::mode() === RegistrationMode::Open;
    }

    public static function closed(): bool
    {
        return self::mode() === RegistrationMode::Closed;
    }

    /**
     * Decide for one email, with the invitation token the request carried, if any.
     */
    public static function decide(string $email, #[SensitiveParameter] ?string $token = null): RegistrationDecision
    {
        $email = InvitationRules::normalizeEmail($email);
        $token = is_string($token) && trim($token) !== '' ? trim($token) : null;

        return match (self::mode()) {
            RegistrationMode::Open => RegistrationDecision::allow(),
            RegistrationMode::Closed => RegistrationDecision::refuse(RegistrationRefusal::Closed),
            RegistrationMode::Domains => self::decideByDomain($email, $token),
            RegistrationMode::Invitation => $token === null
                ? RegistrationDecision::refuse(RegistrationRefusal::InvitationRequired)
                : self::decideByInvitation($email, $token),
        };
    }

    /**
     * Case-insensitive match on the part after the last "@". A subdomain only
     * matches when it is listed itself.
     */
    public static function domainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        return $domain !== '' && in_array($domain, self::domains(), true);
    }

    /**
     * Null when the token names a pending, unexpired invitation (membership or
     * tenant) for this exact email; otherwise the refusal.
     */
    public static function invitationRefusal(string $email, #[SensitiveParameter] string $token): ?RegistrationRefusal
    {
        if (! ConciergeServiceProvider::membershipEnabled()) {
            return RegistrationRefusal::InvitationInvalid;
        }

        $hash = Invitation::hashToken($token);

        $invitation = ConciergeModels::invitation()::query()->where('token_hash', $hash)->first();

        if ($invitation === null && ConciergeServiceProvider::tenantInvitationsEnabled()) {
            $invitation = ConciergeModels::tenantInvitation()::query()->where('token_hash', $hash)->first();
        }

        if (! $invitation instanceof Invitation && ! $invitation instanceof TenantInvitation) {
            return RegistrationRefusal::InvitationInvalid;
        }

        return match (true) {
            $invitation->status === InvitationStatus::Revoked => RegistrationRefusal::InvitationRevoked,
            $invitation->status === InvitationStatus::Accepted => RegistrationRefusal::InvitationInvalid,
            $invitation->isExpired() => RegistrationRefusal::InvitationExpired,
            ! hash_equals(InvitationRules::normalizeEmail($invitation->email), InvitationRules::normalizeEmail($email)) => RegistrationRefusal::InvitationForAnotherEmail,
            default => null,
        };
    }

    private static function decideByDomain(string $email, #[SensitiveParameter] ?string $token): RegistrationDecision
    {
        if (self::domains() === []) {
            Log::warning('concierge.registration.mode is "domains" but no domains are configured; registration is closed. Set CONCIERGE_REGISTRATION_DOMAINS, or change CONCIERGE_REGISTRATION.');

            return RegistrationDecision::refuse(RegistrationRefusal::Closed);
        }

        if (self::domainAllowed($email)) {
            return RegistrationDecision::allow();
        }

        return $token === null
            ? RegistrationDecision::refuse(RegistrationRefusal::DomainNotAllowed)
            : self::decideByInvitation($email, $token);
    }

    private static function decideByInvitation(string $email, #[SensitiveParameter] string $token): RegistrationDecision
    {
        $refusal = self::invitationRefusal($email, $token);

        return $refusal === null ? RegistrationDecision::allow() : RegistrationDecision::refuse($refusal);
    }
}
