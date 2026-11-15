<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function resetUser(array $attrs = []): User
{
    return User::factory()->create(['email' => 'staff@example.com', 'password' => 'old password 12345', ...$attrs]);
}

it('answers 202 for any email and mails a link only to active accounts', function () {
    Notification::fake();
    $user = resetUser();
    resetUser(['email' => 'off@example.com', 'is_active' => false]);

    foreach (['staff@example.com', 'off@example.com', 'nobody@example.com'] as $email) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202);
    }

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('resets the password, ends every session and burns the token', function () {
    $user = resetUser();
    $user->createToken('refresh', ['refresh']);
    $token = Password::createToken($user);
    $body = ['email' => 'staff@example.com', 'token' => $token, 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'];

    $this->postJson('/api/v1/auth/reset-password', $body)->assertNoContent();

    expect($user->fresh()->tokens()->count())->toBe(0);
    expect(Hash::check('a brand new password', $user->fresh()->password))->toBeTrue();
    $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
});

it('rejects a wrong token, a weak password and an inactive account', function () {
    $user = resetUser();
    $off = resetUser(['email' => 'off@example.com', 'is_active' => false]);
    $strong = 'a brand new password';

    $this->postJson('/api/v1/auth/reset-password', ['email' => 'staff@example.com', 'token' => 'nope', 'password' => $strong, 'password_confirmation' => $strong])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    $this->postJson('/api/v1/auth/reset-password', ['email' => 'staff@example.com', 'token' => Password::createToken($user), 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422);
    $this->postJson('/api/v1/auth/reset-password', ['email' => 'off@example.com', 'token' => Password::createToken($off), 'password' => $strong, 'password_confirmation' => $strong])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
});
