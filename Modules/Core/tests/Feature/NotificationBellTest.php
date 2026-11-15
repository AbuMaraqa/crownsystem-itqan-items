<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * جرس الاشعارات في ترويسة لوحة Vue: كل مستخدم يرى ويعلّم اشعاراته فقط.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Permission::firstOrCreate(['name' => 'Notification View List', 'guard_name' => 'web']);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Notification View List');

        return $user;
    }

    private function notify(User $user, array $data): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => $data,
        ]);
    }

    public function test_the_bell_lists_only_the_users_own_notifications(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->notify($me, ['title' => 'Mine', 'message' => 'Hello', 'url' => '/admin/users']);
        $this->notify($other, ['title' => 'Theirs', 'message' => 'Secret']);

        $this->actingAs($me)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine')
            ->assertJsonPath('data.0.url', '/admin/users')
            ->assertJsonPath('data.0.read', false);
    }

    public function test_an_unsafe_link_is_not_sent(): void
    {
        $me = $this->user();
        $this->notify($me, ['title' => 'x', 'message' => 'y', 'url' => 'javascript:alert(1)']);

        $this->actingAs($me)->getJson(route('notifications.latest'))->assertJsonPath('data.0.url', null);
    }

    public function test_marking_read_never_touches_another_users_notification(): void
    {
        $me = $this->user();
        $theirs = $this->notify($this->user(), ['title' => 'Theirs', 'message' => 'm']);

        $this->actingAs($me)->post(route('notifications.read-one', $theirs->id))->assertRedirect();

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_read_all_marks_the_users_notifications(): void
    {
        $me = $this->user();
        $mine = $this->notify($me, ['title' => 'Mine', 'message' => 'm']);

        $this->actingAs($me)->post(route('notifications.read'))->assertRedirect();

        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_the_page_lists_and_filters_the_users_own_notifications(): void
    {
        $me = $this->user();
        $read = $this->notify($me, ['title' => 'Old', 'message' => 'm']);
        $read->markAsRead();
        $this->notify($me, ['title' => 'New', 'message' => 'm']);
        $this->notify($this->user(), ['title' => 'Theirs', 'message' => 'm']);

        $this->actingAs($me)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Core/Notifications/Index')
                ->has('table.data', 2)
                ->where('unread', 1));

        $this->get(route('notifications.index', ['filter' => ['read' => 0]]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('table.data', 1)->where('table.data.0.title', 'New'));
    }

    public function test_opening_marks_read_and_follows_only_a_safe_link(): void
    {
        $me = $this->user();
        $safe = $this->notify($me, ['title' => 'x', 'message' => 'y', 'url' => '/admin/users']);
        $unsafe = $this->notify($me, ['title' => 'x', 'message' => 'y', 'url' => 'javascript:alert(1)']);

        $this->actingAs($me)->get(route('notifications.open', $safe->id))->assertRedirect('/admin/users');
        $this->assertNotNull($safe->fresh()->read_at);

        $this->get(route('notifications.open', $unsafe->id))->assertRedirect();
        $this->assertNotNull($unsafe->fresh()->read_at);

        $theirs = $this->notify($this->user(), ['title' => 'x', 'message' => 'y', 'url' => '/admin']);
        $this->get(route('notifications.open', $theirs->id))->assertNotFound();
    }

    public function test_toggle_bulk_and_delete_stay_within_the_users_notifications(): void
    {
        $me = $this->user();
        $mine = $this->notify($me, ['title' => 'Mine', 'message' => 'm']);
        $theirs = $this->notify($this->user(), ['title' => 'Theirs', 'message' => 'm']);
        $this->actingAs($me);

        $this->post(route('notifications.toggle', $mine->id))->assertRedirect();
        $this->assertNotNull($mine->fresh()->read_at);

        $this->post(route('notifications.bulk'), ['action' => 'unread', 'ids' => [$mine->id]])->assertRedirect();
        $this->assertNull($mine->fresh()->read_at);

        $this->post(route('notifications.bulk'), ['action' => 'delete', 'ids' => [$mine->id, $theirs->id]])->assertRedirect();
        $this->assertNull($mine->fresh());
        $this->assertNotNull($theirs->fresh());

        $this->delete(route('notifications.destroy', $theirs->id))->assertRedirect();
        $this->assertNotNull($theirs->fresh());
    }

    public function test_the_bell_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('notifications.latest'))->assertForbidden();
    }
}
