<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_links_to_password_reset_request(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Forgot password?')
            ->assertSee(route('admin.password.request'));
    }

    public function test_user_can_request_a_password_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post(route('admin.password.email'), [
            'email' => $user->email,
        ])->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_user_can_reset_password_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'token-reset@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $token = Password::createToken($user);

        $this->post(route('admin.password.reset.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('admin.login'))
            ->assertSessionHas('status', 'Your password has been reset. You can now sign in.');

        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_authenticated_user_can_change_own_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->put(route('admin.password.update'), [
                'current_password' => 'current-password',
                'password' => 'updated-password',
                'password_confirmation' => 'updated-password',
            ])->assertRedirect(route('admin.password.edit'))
            ->assertSessionHas('status', 'Your password has been updated.');

        $this->assertTrue(Hash::check('updated-password', $user->refresh()->password));
    }

    public function test_current_password_is_required_to_change_own_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)
            ->from(route('admin.password.edit'))
            ->put(route('admin.password.update'), [
                'current_password' => 'incorrect-password',
                'password' => 'updated-password',
                'password_confirmation' => 'updated-password',
            ])->assertRedirect(route('admin.password.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password', $user->refresh()->password));
    }

    public function test_global_admin_can_reset_another_users_password(): void
    {
        $globalAdmin = User::factory()->create([
            'role' => 'admin',
            'application' => null,
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'application' => 'legal',
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($globalAdmin)
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'application' => 'legal',
                'password' => 'admin-reset-password',
                'password_confirmation' => 'admin-reset-password',
            ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue(Hash::check('admin-reset-password', $user->refresh()->password));
    }
}
