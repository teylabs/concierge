<?php

use Tey\Concierge\Concierge;
use Tey\Concierge\Data\UI\UserColumn;
use Workbench\App\Models\User;

it('sends no columns until the app adds some', function () {
    $concierge = new Concierge;

    expect($concierge->userColumnDefinitions())->toBe([])
        ->and($concierge->userColumnValues(new User(['id' => 5])))->toBe([]);
});

it('adds the app fields to a user row in the order of the labels', function () {
    $concierge = (new Concierge)->userColumns(
        ['plan' => 'Plan', 'seats' => 'Seats'],
        fn (User $user) => ['seats' => $user->id * 2, 'plan' => 'Pro'],
    );
    $ada = new User(['id' => 5]);

    expect($concierge->userColumnDefinitions())->toEqual([new UserColumn('plan', 'Plan'), new UserColumn('seats', 'Seats')])
        ->and($concierge->userColumnValues($ada))->toBe(['plan' => 'Pro', 'seats' => 10]);
});

it('renders missing keys empty and drops keys without a label', function () {
    $concierge = (new Concierge)->userColumns(
        ['plan' => 'Plan', 'seats' => 'Seats'],
        fn () => ['plan' => 'Pro', 'secret' => 'hidden'],
    );

    expect($concierge->userColumnValues(new User(['id' => 5])))->toBe(['plan' => 'Pro', 'seats' => null]);
});

it('replaces the columns when the app sets them again', function () {
    $concierge = (new Concierge)->userColumns(['plan' => 'Plan'], fn () => ['plan' => 'Pro']);
    $concierge->userColumns(['seats' => 'Seats'], fn () => ['seats' => 3]);

    expect($concierge->userColumnDefinitions())->toEqual([new UserColumn('seats', 'Seats')])
        ->and($concierge->userColumnValues(new User(['id' => 5])))->toBe(['seats' => 3]);
});
