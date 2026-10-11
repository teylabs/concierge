<?php

use Tey\Concierge\Concierge;
use Tey\Concierge\Data\UI\UserAvatar;
use Tey\Concierge\Data\UI\UserSummary;
use Workbench\App\Models\User;

it('takes the first letters of the first and last words of a name', function (string $name, string $initials) {
    expect(UserAvatar::initials($name))->toBe($initials);
})->with([
    'two words' => ['Ada Lovelace', 'AL'],
    'three words use the first and the last' => ['Ana María López', 'AL'],
    'one word' => ['Ada', 'A'],
    'lower case is upper-cased' => ['ada lovelace', 'AL'],
    'surrounding and repeated whitespace' => ["  Ada \t Lovelace\n", 'AL'],
    'accented letter' => ['émilie durand', 'ÉD'],
    'decomposed accent keeps its combining mark' => ["e\u{0301}milie durand", "E\u{0301}D"],
    'non-Latin script' => ['李 小龍', '李小'],
    'emoji sequence stays whole' => ['👩‍🚀 Nova', '👩‍🚀N'],
]);

it('falls back to the first letter of the email when there is no name', function () {
    expect(UserAvatar::initials('', 'zoë@example.com'))->toBe('Z')
        ->and(UserAvatar::initials('   ', 'ada@example.com'))->toBe('A')
        ->and(UserAvatar::initials('', ''))->toBe('');
});

it('keeps the same colour for the same key', function () {
    expect(UserAvatar::colour('11'))->toBe(UserAvatar::colour('11'))
        ->and(UserAvatar::colour(11))->toBe(UserAvatar::colour('11'))
        ->and(UserAvatar::colour('11'))->toBe('sky')
        ->and(UserAvatar::colour('01JAB0000000000000000000PX'))->toBe('sky');
});

it('spreads keys across the whole palette', function () {
    $colours = collect(range(1, 200))->map(fn (int $key) => UserAvatar::colour($key))->unique()->sort()->values()->all();

    expect($colours)->toBe(collect(UserAvatar::PALETTE)->sort()->values()->all());
});

it('serializes the url, initials and colour', function () {
    expect(UserAvatar::of('11', 'Ada Lovelace', 'ada@example.com')->toArray())
        ->toBe(['url' => null, 'initials' => 'AL', 'colour' => 'sky'])
        ->and(UserAvatar::of(11, '', 'ada@example.com', 'https://host.test/ada.jpg')->toArray())
        ->toBe(['url' => 'https://host.test/ada.jpg', 'initials' => 'A', 'colour' => 'sky']);
});

it('gives a user summary initials when no avatar is passed', function () {
    expect(new UserSummary('11', 'Ada Lovelace', 'ada@example.com')->toArray())->toBe([
        'id' => '11',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'avatar' => ['url' => null, 'initials' => 'AL', 'colour' => 'sky'],
    ]);
});

it('shows initials for a host user until the app supplies a photo', function () {
    $ada = new User(['id' => 5, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    $grace = new User(['id' => 6, 'name' => 'Grace Hopper', 'email' => 'grace@example.test']);
    $concierge = app(Concierge::class);

    expect($concierge->avatar($ada)->toArray())
        ->toBe(['url' => null, 'initials' => 'AL', 'colour' => UserAvatar::colour(5)]);

    $concierge->avatarUsing(fn (User $user) => $user->id === 5 ? "https://host.test/photos/{$user->id}.jpg" : null);

    expect($concierge->avatar($ada)->url)->toBe('https://host.test/photos/5.jpg')
        ->and($concierge->avatar($grace)->url)->toBeNull()
        ->and(UserSummary::for($ada)->toArray())->toBe([
            'id' => '5',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'avatar' => ['url' => 'https://host.test/photos/5.jpg', 'initials' => 'AL', 'colour' => UserAvatar::colour(5)],
        ]);

    $concierge->avatarUsing(null);

    expect($concierge->avatar($ada)->url)->toBeNull();
});
