<?php

namespace Tey\Concierge\Support;

use Carbon\CarbonInterface;
use Tey\Concierge\Models\Invitation;

final class InvitationTokens
{
    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => Invitation::hashToken($token)];
    }

    public static function expiry(): CarbonInterface
    {
        return now()->addDays((int) config('concierge.invitations.expires_after_days', 7));
    }
}
