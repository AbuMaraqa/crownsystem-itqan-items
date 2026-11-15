<?php

namespace Tests\Feature;

use Modules\Core\Entities\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        // صفحة الدخول بتصميم malbo، ومبدّل اللغة يعمل بـ ?lang= لان الرابط بلا بادئة لغة.
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->where('locales.0.url', fn (string $url) => str_contains($url, 'lang=')));
    }

    public function test_the_forgot_password_screen_is_the_inertia_page(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }
}
