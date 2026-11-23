<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use Modules\Core\Entities\User;
use Modules\Core\Notifications\GeneralNotification;
use Modules\Core\Support\LiveUpdate;
use Modules\Items\Events\ProductUpdated;
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
    /**
     * خادم البث الحي (Reverb) متوقف: يُسجَّل الخطأ، ويتم حفظ الصنف ويُخزَّن الاشعار كالمعتاد.
     */
    public function test_a_live_server_that_is_down_never_blocks_the_action(): void
    {
        Exceptions::fake();
        Broadcast::extend('down', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('cURL error 7: Failed to connect to 127.0.0.1 port 8080');
            }
        });
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
        $user = User::factory()->create();

        LiveUpdate::send(new ProductUpdated);
        $user->notify(new GeneralNotification('طلبية جديدة', 'وصلت طلبية للإنتاج'));

        $this->assertSame(1, $user->notifications()->count());
        Exceptions::assertReported(BroadcastException::class);
    }
}
