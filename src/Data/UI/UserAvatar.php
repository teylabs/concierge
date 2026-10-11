<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/**
 * A person's avatar: the host's photo URL when it has one, and otherwise the
 * initials on a colour that stays the same for that person everywhere.
 * Nothing here contacts an outside service.
 */
#[TypeScript]
readonly class UserAvatar extends Props
{
    /** Names of the colours in resources/css/concierge.css (--concierge-avatar-*). */
    public const array PALETTE = ['slate', 'indigo', 'violet', 'rose', 'amber', 'emerald', 'teal', 'sky'];

    public function __construct(
        public ?string $url,
        public string $initials,
        public string $colour,
    ) {}

    /**
     * @param  string|int  $key  the user's key; it picks the colour
     */
    public static function of(string|int $key, string $name, string $email = '', ?string $url = null): self
    {
        return new self($url, self::initials($name, $email), self::colour($key));
    }

    /**
     * The first letters of the first and last words of the name, one letter
     * for a single word, and the first letter of the email when there is no
     * name. Letters are graphemes, so accents, non-Latin scripts and emoji
     * come through whole.
     */
    public static function initials(string $name, string $email = ''): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return mb_strtoupper(self::firstGrapheme(trim($email)));
        }

        $first = self::firstGrapheme($words[0]);
        $last = count($words) > 1 ? self::firstGrapheme($words[array_key_last($words)]) : '';

        return mb_strtoupper($first.$last);
    }

    /** A palette name chosen by the key alone, so a person keeps their colour everywhere. */
    public static function colour(string|int $key): string
    {
        return self::PALETTE[crc32((string) $key) % count(self::PALETTE)];
    }

    private static function firstGrapheme(string $text): string
    {
        return preg_match('/^\X/u', $text, $match) === 1 ? $match[0] : '';
    }
}
