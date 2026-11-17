<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\User;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Notifications\SystemNotification;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\NotificationPermissions;
use Tests\TestCase;

/**
 * اشعارات المستندات: من يوجه له المستند يصله اشعار عند الانشاء والتحويل وتغيير الحالة،
 * ويستطيع تحديده كمقروء او غير مقروء من الجرس والصفحة والـ API.
 */
class DocumentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function quotation(User $client, array $attributes = []): SalesQuotation
    {
        return SalesQuotation::create($attributes + [
            'reference_no' => 'SQ-'.Str::random(8),
            'client_id' => $client->id,
            'quotation_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => SalesQuotationStatus::DRAFT->value(),
        ]);
    }

    public function test_client_is_notified_when_a_document_is_sent_and_its_status_changes(): void
    {
        $sender = User::factory()->create(['name' => 'Sender']);
        $client = User::factory()->create();
        $this->actingAs($sender);

        $quotation = $this->quotation($client);

        $this->assertSame(1, $client->notifications()->count());
        $this->assertSame(0, $sender->notifications()->count(), 'the user who sends the document is not notified');

        $notification = $client->notifications()->first();
        $this->assertSame(SystemNotification::class, $notification->type);
        $this->assertNull($notification->read_at);
        $this->assertSame('document.sent', $notification->data['event']);
        $this->assertSame($quotation->getMorphClass(), $notification->data['subject_type']);
        $this->assertSame($quotation->id, $notification->data['subject_id']);
        $this->assertSame(route('accounting.sales-quotations.edit', $quotation), $notification->data['url']);

        // يعرض بلغة القارئ وقت القراءة.
        app()->setLocale('ar');
        $presented = app(NotificationService::class)->present($notification);
        $this->assertSame('مستند جديد: عرض سعر بيع', $presented['title']);
        $this->assertStringContainsString($quotation->reference_no, $presented['message']);
        $this->assertStringContainsString('Sender', $presented['message']);
        app()->setLocale('en');

        // صفحة التعديل تحمل المستند من جديد (ليس نفس النسخة التي انشئت للتو).
        $quotation = $quotation->fresh();
        $quotation->update(['status' => SalesQuotationStatus::APPROVED->value()]);
        $this->assertSame(2, $client->notifications()->count());
        $statusNotification = $client->notifications()->where('data->event', 'document.status_changed')->firstOrFail();

        app()->setLocale('ar');
        $this->assertStringContainsString(
            SalesQuotationStatus::APPROVED->getLabel(),
            app(NotificationService::class)->present($statusNotification)['message'],
        );
        $this->assertNotSame('Approved', SalesQuotationStatus::APPROVED->getLabel(), 'the status label is shown in the reader\'s language');
        app()->setLocale('en');

        // تحويل المستند لعميل آخر: يصله اشعار الارسال.
        $otherClient = User::factory()->create();
        $quotation->update(['client_id' => $otherClient->id]);
        $this->assertSame(1, $otherClient->notifications()->where('data->event', 'document.sent')->count());

        // العمليات الجماعية والاستيراد يمكن اسكاتها.
        NotificationService::withoutNotifications(fn () => $this->quotation($client));
        $this->assertSame(2, $client->notifications()->count());
    }

    public function test_read_state_is_scoped_to_the_owner(): void
    {
        $service = app(NotificationService::class);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $service->send($owner, 'First', skipSender: false);
        $service->send($owner, 'Second', skipSender: false);
        $service->send($stranger, 'Not yours', skipSender: false);

        $first = $owner->notifications()->where('data->title_key', 'First')->firstOrFail();
        $foreign = $stranger->notifications()->firstOrFail();

        $this->assertSame(2, $service->unreadCount($owner));

        $this->assertTrue($service->toggleRead($owner, $first->id));
        $this->assertSame(1, $service->unreadCount($owner));
        $this->assertFalse($service->toggleRead($owner, $first->id));
        $this->assertSame(2, $service->unreadCount($owner));

        $this->assertSame(0, $service->markAsRead($owner, $foreign->id), 'cannot touch another user\'s notification');
        $this->assertNull($foreign->fresh()->read_at);

        $this->assertSame(2, $service->markAllAsRead($owner));
        $this->assertSame(0, $service->unreadCount($owner));
        $this->assertSame(1, $service->unreadCount($stranger));

        $this->assertSame(1, $service->markAsUnread($owner, $first->id));
        $this->assertSame(1, $service->query($owner, read: false)->count());
        $this->assertSame(1, $service->query($owner, read: true)->count());
    }

    public function test_bell_page_and_api_mark_notifications_read_and_unread(): void
    {
        NotificationPermissions::sync();
        $user = User::factory()->create();
        $user->givePermissionTo('Notification View List');
        $stranger = User::factory()->create();

        $service = app(NotificationService::class);
        $service->send($user, 'Hello', 'Body', skipSender: false);
        $service->send($stranger, 'Private', skipSender: false);
        $id = $user->notifications()->firstOrFail()->id;
        $foreignId = $stranger->notifications()->firstOrFail()->id;

        $this->actingAs($user)->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        // الجرس يعرض اشعارات المستخدم وحده.
        $this->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Hello');

        $this->post(route('notifications.toggle', $id))->assertRedirect();
        $this->assertNotNull(DatabaseNotification::find($id)->read_at);

        $this->post(route('notifications.toggle', $id))->assertRedirect();
        $this->assertNull(DatabaseNotification::find($id)->read_at);

        $this->post(route('notifications.read'))->assertRedirect();
        $this->assertSame(0, $service->unreadCount($user));

        // صفحة الاشعارات (Inertia) تعرض اشعارات المستخدم وحده.
        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.title', 'Hello'));
        $this->assertContains('can:Notification View List', app('router')->getRoutes()->getByName('notifications.index')->gatherMiddleware());

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/notifications/{$id}/unread")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/notifications?status=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('meta.unread_count', 1);
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        $this->postJson("/api/v1/notifications/{$foreignId}/read")->assertNotFound();
        $this->deleteJson("/api/v1/notifications/{$foreignId}")->assertNotFound();
        $this->assertNull(DatabaseNotification::find($foreignId)->read_at);

        $this->deleteJson("/api/v1/notifications/{$id}")->assertOk();
        $this->assertNull(DatabaseNotification::find($id));
    }
}
