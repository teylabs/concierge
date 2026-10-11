<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserSummary extends Props
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        ?UserAvatar $avatar = null,
    ) {
        $this->avatar = $avatar ?? UserAvatar::of($id, $name, $email);
    }

    /** Declared after the constructor so it serializes after the promoted properties. */
    public UserAvatar $avatar;

    /** The summary of a host user, with the photo from Concierge::avatarUsing() when the app has one. */
    public static function for(Member $user): self
    {
        return new self(
            (string) $user->getKey(),
            $user->conciergeDisplayName(),
            $user->conciergeEmail(),
            app(Concierge::class)->avatar($user),
        );
    }
}
