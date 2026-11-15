<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Entities\User;
use Modules\Core\Livewire\GlobalNotificationListener;
use Modules\Core\Notifications\SystemNotification;
use Tests\TestCase;

class LiveNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_subscribe_to_own_private_notification_channel(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-'.$user->receivesBroadcastNotificationsOn(),
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-'.$other->receivesBroadcastNotificationsOn(),
            ])
            ->assertForbidden();
    }

    public function test_clicking_a_live_toast_marks_it_read_and_opens_its_link(): void
    {
        $user = User::factory()->create();
        $notification = $this->notificationFor($user, '/admin/settings');

        $this->actingAs($user);

        Livewire::test(GlobalNotificationListener::class)
            ->dispatch('notification-open', id: $notification->id)
            ->assertRedirect('/admin/settings');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_user_cannot_open_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $notification = $this->notificationFor($owner, '/admin/settings');

        $this->actingAs(User::factory()->create());

        Livewire::test(GlobalNotificationListener::class)
            ->dispatch('notification-open', id: $notification->id)
            ->assertNoRedirect();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_unsafe_links_are_not_followed(): void
    {
        $user = User::factory()->create();
        $notification = $this->notificationFor($user, 'javascript:alert(1)');

        $this->actingAs($user);

        Livewire::test(GlobalNotificationListener::class)
            ->dispatch('notification-open', id: $notification->id)
            ->assertNoRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    private function notificationFor(User $user, string $url): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => ['title' => 'Test', 'message' => 'Body', 'url' => $url, 'color' => 'success'],
        ]);
    }
}
