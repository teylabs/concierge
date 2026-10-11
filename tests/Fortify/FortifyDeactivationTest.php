<?php

use Illuminate\Support\Facades\Hash;
use Tey\Concierge\Models\Account;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = host_user('alice');
    $this->user->forceFill(['password' => Hash::make('correct-horse-battery')])->save();
});

function deactivate_account(User $user): void
{
    Account::query()->updateOrCreate(['user_id' => $user->id], ['status' => 'deactivated', 'deactivated_at' => now()]);
}

it('refuses a deactivated user through the Fortify login with the message on the email field', function () {
    deactivate_account($this->user);

    $this->post('/login', ['email' => 'alice@example.test', 'password' => 'correct-horse-battery'])
        ->assertSessionHasErrors(['email' => 'This account is deactivated.']);

    $this->assertGuest();
});

it('signs in an active user and a restored one', function () {
    deactivate_account($this->user);
    Account::query()->find($this->user->id)->update(['status' => 'active', 'deactivated_at' => null]);

    $this->post('/login', ['email' => 'alice@example.test', 'password' => 'correct-horse-battery'])->assertRedirect('/home');

    $this->assertAuthenticatedAs($this->user);
});
