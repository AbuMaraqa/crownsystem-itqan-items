<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\StockAlert;
use Modules\Accounting\Entities\StockLimitChange;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockAlertLevel;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockAlertService;
use Modules\Accounting\Services\StockFollowUpService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Support\InventoryPermissions;
use Modules\Accounting\Support\StockLimits;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * تنبيهات المخزون وقسم المتابعة كما في متطلبات العميل (inventory-alerts-requirements.pdf):
 * حالات الصنف، الاشعار عند تغيّر الحالة فقط، الخروج من المتابعة بعد التوريد، الحدود الصفرية،
 * اقتراحا الطلب، الصلاحيات، وسجل تعديل الحدود.
 */
class StockFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $partsUser;

    private User $aluminumUser;

    protected function setUp(): void
    {
        parent::setUp();

        InventoryPermissions::sync();

        $this->partsUser = User::factory()->create();
        $this->partsUser->givePermissionTo(['Parts Stock Follow-up View List', 'Stock Limit Update', 'Stock Item Card View']);
        $this->aluminumUser = User::factory()->create();
        $this->aluminumUser->givePermissionTo(['Aluminum Stock Follow-up View List']);

        $this->actingAs($this->partsUser);

        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    public function test_spec_example_movement_sequence(): void
    {
        // المثال في القسم 4: الحد الاعلى 600، الادنى 1 = 300، الادنى 2 = 100.
        $product = $this->product('مفصلة باب', 600, 300, 100);

        $this->receive($product, 400, StockMovementType::OPENING);
        $this->assertNull($this->alert($product));
        $this->assertSame(0, $this->notificationsFor($this->partsUser));

        $this->issue($product, 100); // 300: تنبيه + دخول القسم
        $this->assertSame(StockAlertLevel::WARNING, $this->alert($product)->level);
        $this->assertSame(1, $this->notificationsFor($this->partsUser));

        $this->issue($product, 60); // 240: نفس الحالة، لا اشعار جديد
        $this->assertSame(StockAlertLevel::WARNING, $this->alert($product)->level);
        $this->assertSame(1, $this->notificationsFor($this->partsUser));

        $this->issue($product, 150); // 90: يجب الطلب، مع اقتراح 600 − 90 = 510
        $this->assertSame(StockAlertLevel::REORDER, $this->alert($product)->level);
        $this->assertSame(2, $this->notificationsFor($this->partsUser));
        $latest = $this->latestNotification($this->partsUser);
        $this->assertSame('510', $latest->data['params']['by_max']);
        $this->assertSame(route('accounting.inventory.follow-up.parts', ['item' => $product->id]), $latest->data['url']);

        $this->issue($product, 90); // 0: نفد
        $this->assertSame(StockAlertLevel::OUT, $this->alert($product)->level);
        $this->assertSame(3, $this->notificationsFor($this->partsUser));

        $this->receive($product, 600); // توريد: يخرج من المتابعة وتُغلق اشعاراته دون حذفها
        $this->assertNull($this->alert($product));
        $this->assertSame(3, $this->notificationsFor($this->partsUser));
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $this->partsUser->id)->whereNull('read_at')->count());
        $this->assertTrue(DatabaseNotification::query()->get()->every(fn ($n) => filled($n->data['resolved_at'] ?? null)));

        // نزوله من جديد يعطي اشعاراً جديداً.
        $this->issue($product, 350);
        $this->assertSame(4, $this->notificationsFor($this->partsUser));
    }

    public function test_jumping_over_two_limits_sends_one_notification_with_the_final_status(): void
    {
        $product = $this->product('برغي', 600, 300, 100);
        $this->receive($product, 400);

        $this->issue($product, 350); // 50: من طبيعي الى يجب الطلب مباشرة

        $this->assertSame(1, $this->notificationsFor($this->partsUser));
        $this->assertSame(StockAlertLevel::REORDER->value, $this->latestNotification($this->partsUser)->data['extra']['level']);
    }

    public function test_improving_status_sends_no_notification(): void
    {
        $product = $this->product('زاوية', 600, 300, 100);
        $this->receive($product, 50);
        $this->assertSame(1, $this->notificationsFor($this->partsUser));

        $this->receive($product, 200); // 250: تحسّن الى تنبيه، بلا اشعار ويبقى في القسم

        $this->assertSame(StockAlertLevel::WARNING, $this->alert($product)->level);
        $this->assertSame(1, $this->notificationsFor($this->partsUser));
    }

    public function test_notifications_go_only_to_users_following_the_item_category(): void
    {
        $parts = $this->product('قفل', 600, 300, 100);
        $profile = $this->product('بروفيل ابيض', 60, 30, 10, 'weight');

        $this->receive($parts, 10);
        $this->receive($profile, 5, weight: 40);

        $this->assertSame(1, $this->notificationsFor($this->partsUser));
        $this->assertSame(1, $this->notificationsFor($this->aluminumUser));
        $this->assertStringContainsString('قفل', $this->latestNotification($this->partsUser)->data['params']['item']);
        $this->assertStringContainsString('بروفيل', $this->latestNotification($this->aluminumUser)->data['params']['item']);
    }

    public function test_zero_limits_make_the_item_unmonitored(): void
    {
        $product = $this->product('مسكة', 600, 300, 100);
        $this->receive($product, 50);
        $this->assertNotNull($this->alert($product));

        $product->update(['max_limit' => 0, 'min_limit_1' => 0, 'min_limit_2' => 0]);

        $this->assertNull($this->alert($product));
        $this->assertTrue(filled($this->latestNotification($this->partsUser)->data['resolved_at'] ?? null));
        $this->assertNull(app(StockFollowUpService::class)->suggestions($product->fresh()));

        $this->issue($product, 50); // صفر، لكنه غير مراقَب: لا قسم ولا اشعار
        $this->assertNull($this->alert($product));
        $this->assertSame(1, $this->notificationsFor($this->partsUser));

        // اعادة الحدود تحسب الحالة فوراً.
        $product->update(['max_limit' => 600, 'min_limit_1' => 300, 'min_limit_2' => 100]);
        $this->assertSame(StockAlertLevel::OUT, $this->alert($product)->level);
        $this->assertSame(2, $this->notificationsFor($this->partsUser));
    }

    public function test_limit_rules(): void
    {
        $this->assertSame([], StockLimits::errors(600, 300, 100));
        $this->assertSame([], StockLimits::errors(0, 0, 0));
        $this->assertSame([], StockLimits::errors(null, '', null));
        $this->assertArrayHasKey('min_limit_2', StockLimits::errors(600, 300, 0));
        $this->assertArrayHasKey('min_limit_1', StockLimits::errors(300, 300, 100));
        $this->assertArrayHasKey('min_limit_2', StockLimits::errors(600, 300, 300));
        $this->assertArrayHasKey('max_limit', StockLimits::errors(-1, 0, 0));
    }

    public function test_every_limit_change_is_logged_and_users_without_permission_cannot_change_limits(): void
    {
        $product = $this->product('مفصلة', 600, 300, 100);
        $product->update(['max_limit' => 900]);

        $change = StockLimitChange::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        $this->assertSame(600.0, (float) $change->old_max_limit);
        $this->assertSame(900.0, (float) $change->new_max_limit);
        $this->assertSame($this->partsUser->id, $change->changed_by);

        // لا يكفي تعديل الصنف: الحدود تحتاج «تعديل الحدود».
        $this->actingAs($this->aluminumUser);
        $product->fresh()->update(['max_limit' => 50, 'shelf' => 'E10']);

        $product->refresh();
        $this->assertSame(900.0, (float) $product->max_limit);
        $this->assertSame('E10', $product->shelf);
    }

    public function test_order_suggestions_follow_the_spec_example(): void
    {
        // مثال القسم 3: المسحوب خلال آخر 6 اشهر 540 والكمية الحالية 90.
        $service = app(StockFollowUpService::class);
        $today = $service->today();
        $product = $this->product('بروفيل ب', 600, 300, 100);

        $this->receive($product, 630, StockMovementType::OPENING, $today->subMonths(8)->toDateString());
        $this->issue($product, 300, $today->subMonths(3)->toDateString());
        $this->issue($product, 240, $today->subDays(3)->toDateString());

        $suggestions = $service->suggestions($product->fresh());

        $this->assertSame(510.0, $suggestions['by_max']['quantity']);
        $this->assertSame(540.0, $suggestions['by_usage']['withdrawn']);
        $this->assertSame(450.0, $suggestions['by_usage']['quantity']);
        $this->assertSame(StockFollowUpService::ORDER, $suggestions['by_usage']['state']);
    }

    public function test_order_suggestions_without_withdrawals_or_need(): void
    {
        $product = $this->product('حلق', 600, 300, 100);
        $this->receive($product, 250);

        $suggestions = app(StockFollowUpService::class)->suggestions($product->fresh());

        $this->assertSame(StockFollowUpService::ORDER, $suggestions['by_max']['state']);
        $this->assertSame(StockFollowUpService::NO_DATA, $suggestions['by_usage']['state']);

        $full = app(StockFollowUpService::class)->suggestions($product->fresh(), 700);
        $this->assertSame(StockFollowUpService::NONE, $full['by_max']['state']);
    }

    public function test_out_and_back_in_within_one_operation_does_not_alert(): void
    {
        $product = $this->product('عجل', 600, 300, 100);
        $this->receive($product, 400);

        // مثل التحويل بين مخزنين: يخرج ثم يدخل، والرصيد الكلي لا يتغير.
        app(StockAlertService::class)->batch(function () use ($product) {
            $this->issue($product, 380);
            $this->receive($product, 380);
        });

        $this->assertNull($this->alert($product));
        $this->assertSame(0, $this->notificationsFor($this->partsUser));
    }

    public function test_daily_ledger_balances_and_day_movements(): void
    {
        $service = app(StockFollowUpService::class);
        $today = $service->today();
        $product = $this->product('مقبض', 600, 300, 100);

        $this->receive($product, 400, StockMovementType::OPENING, $today->subDays(40)->toDateString());
        $this->issue($product, 100, $today->subDays(2)->toDateString());
        $this->issue($product, 60, $today->toDateString());
        $this->receive($product, 20, StockMovementType::SALES_RETURN, $today->toDateString());

        $ledger = $service->dailyLedger($product->fresh(), $today->subDays(29), $today);

        $this->assertCount(30, $ledger['days']);
        $this->assertSame(400.0, $ledger['opening']);
        $this->assertSame(260.0, $ledger['closing']);
        $this->assertSame(round($ledger['opening'] + $ledger['incoming'] - $ledger['outgoing'], 4), $ledger['closing']);

        $todayRow = $ledger['days'][0];
        $this->assertSame($today->toDateString(), $todayRow['date']);
        $this->assertSame(300.0, $todayRow['opening']);
        $this->assertSame(20.0, $todayRow['incoming']);
        $this->assertSame(60.0, $todayRow['outgoing']);
        $this->assertSame(StockAlertLevel::WARNING, $todayRow['level']);

        $movements = $service->dayMovements($product, $today);
        $this->assertCount(2, $movements);
        $this->assertSame(260.0, (float) $movements->last()->balance_after);
    }

    public function test_follow_up_page_lists_items_and_opens_details(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $product = $this->product('مفصلة مخفية', 600, 300, 100);
        $this->receive($product, 90);
        $today = app(StockFollowUpService::class)->today()->toDateString();

        $this->get(route('accounting.inventory.follow-up.parts', ['item' => $product->id, 'day' => $today]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/StockFollowUp/Index')
                ->where('groups.0.rows.0.name', 'مفصلة مخفية')
                ->where('details.id', $product->id)
                ->where('details.day', $today)
                ->where('details.movements', fn ($movements) => count($movements) === 1)
                ->where('canEditLimits', true));

        $this->put(route('accounting.inventory.follow-up.parts.limits', $product->id), ['max_limit' => 600, 'min_limit_1' => 300, 'min_limit_2' => 400])
            ->assertSessionHasErrors('min_limit_2');
        $this->put(route('accounting.inventory.follow-up.parts.limits', $product->id), ['max_limit' => 600, 'min_limit_1' => 300, 'min_limit_2' => 50])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(50.0, (float) $product->fresh()->min_limit_2);

        // صنف قطع لا يُفتح من صفحة الالمنيوم، ولا تُعدَّل حدوده منها.
        $this->actingAs($this->aluminumUser->givePermissionTo('Stock Limit Update'));
        $this->get(route('accounting.inventory.follow-up.aluminum', ['item' => $product->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('details', null)->where('groups', []));
        $this->put(route('accounting.inventory.follow-up.aluminum.limits', $product->id), ['max_limit' => 0, 'min_limit_1' => 0, 'min_limit_2' => 0])
            ->assertNotFound();
    }

    public function test_follow_up_routes_require_the_category_permission(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->actingAs($this->aluminumUser)
            ->get(route('accounting.inventory.follow-up.parts'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('accounting.inventory.follow-up.aluminum'))
            ->assertForbidden();
    }

    /**
     * الحدود تُكتب بلا مراقِب، فيبدأ الصنف خارج القسم وتبدأ حالته من اول حركة في الاختبار.
     */
    private function product(string $name, float $max, float $min1, float $min2, string $pricingType = 'simple'): Product
    {
        $product = new Product([
            'slug' => 'fu-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'B'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'pricing_type' => $pricingType,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();
        $product->forceFill(['max_limit' => $max, 'min_limit_1' => $min1, 'min_limit_2' => $min2])->saveQuietly();

        return $product;
    }

    private function receive(Product $product, float $quantity, StockMovementType $type = StockMovementType::RECEIPT, ?string $date = null, float $weight = 0): void
    {
        app(StockLedgerService::class)->receive([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'weight' => $weight,
            'unit_cost' => 5,
            'type' => $type,
            'date' => $date ?? app(StockFollowUpService::class)->today()->toDateString(),
        ]);
    }

    private function issue(Product $product, float $quantity, ?string $date = null): void
    {
        app(StockLedgerService::class)->issue([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'type' => StockMovementType::DELIVERY,
            'date' => $date ?? app(StockFollowUpService::class)->today()->toDateString(),
        ]);
    }

    private function alert(Product $product): ?StockAlert
    {
        return StockAlert::query()->where('product_id', $product->id)->first();
    }

    private function notificationsFor(User $user): int
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('data->event', StockAlertService::EVENT)->count();
    }

    private function latestNotification(User $user): DatabaseNotification
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('data->event', StockAlertService::EVENT)->latest()->orderByDesc('id')->firstOrFail();
    }
}
