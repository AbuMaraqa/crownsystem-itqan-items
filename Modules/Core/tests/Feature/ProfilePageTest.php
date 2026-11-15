<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Tests\TestCase;

/**
 * الملف الشخصي في لوحة Vue: يفتحه كل مستخدم لحسابه، الاسم والبريد للعرض فقط،
 * وتغيير كلمة المرور والتحقق بخطوتين يطلبان كلمة المرور الحالية.
 */
class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->user = User::factory()->create(['name' => 'سارة', 'password' => Hash::make('old-password')]);
        $this->actingAs($this->user);
    }

    public function test_any_user_opens_their_profile(): void
    {
        $this->get(route('profile.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/Profile/Index')
                ->where('profile.name', 'سارة')
                ->where('twoFactor.enabled', false));
    }

    public function test_the_profile_form_never_changes_the_name_or_email(): void
    {
        $email = $this->user->email;

        $this->put(route('profile.update'), ['name' => 'اسم آخر', 'email' => 'other@example.com'])->assertRedirect(route('profile.index'));

        $this->user->refresh();
        $this->assertSame('سارة', $this->user->name);
        $this->assertSame($email, $this->user->email);
    }

    public function test_the_password_changes_with_the_current_one(): void
    {
        $this->put(route('profile.password'), [
            'current_password' => 'wrong',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('profile.password'), [
            'current_password' => 'old-password',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('New-password-123', $this->user->fresh()->password));
    }

    public function test_two_factor_setup_needs_the_password(): void
    {
        $this->post(route('profile.two-factor.enable'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertNull($this->user->fresh()->two_factor_secret);

        $this->post(route('profile.two-factor.enable'), ['password' => 'old-password'])->assertRedirect(route('profile.index'));
        $this->assertNotNull($this->user->fresh()->two_factor_secret);
    }
}
