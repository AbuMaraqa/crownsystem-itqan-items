<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Entities\User;
use Tests\TestCase;

/**
 * الاشعارات الحية: كل مستخدم يشترك في قناته الخاصة وحدها. فتح التنبيه (تعليمه مقروءاً
 * واتباع رابطه الآمن فقط) يُختبر في NotificationBellTest عبر notifications.open.
 */
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
}
