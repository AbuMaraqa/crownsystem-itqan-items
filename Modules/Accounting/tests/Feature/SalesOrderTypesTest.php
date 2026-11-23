<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesOrderType;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Support\SalesPermissions;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Tests\TestCase;

/**
 * أنواع طلبيات البيع كما في «دليل أنواع الطلبيات» من الشركة: لكل نوع مهلة قصوى للتحضير والجاهزية
 * للفوترة تُحسب من تأكيد الطلبية. القائمة تُعدَّل من صفحتها، والطلبية تحفظ موعدها عند التأكيد،
 * وقائمة الطلبيات تُظهر النوع والموعد والمتأخرة.
 */
class SalesOrderTypesTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    private const TYPE_PERMISSIONS = ['Sales Order Type View List', 'Sales Order Type Create', 'Sales Order Type Update', 'Sales Order Type Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
        SalesPermissions::sync();
        auth()->user()->givePermissionTo(self::TYPE_PERMISSIONS);
    }

    private function type(string $name): SalesOrderType
    {
        return SalesOrderType::query()->where('name', $name)->sole();
    }

    public function test_the_company_guide_types_are_ready_and_editable(): void
    {
        $this->assertSame(7, SalesOrderType::query()->count());
        $this->assertSame(3, $this->type('تحضير من المخزن')->lead_days);
        $this->assertSame(90, $this->type('توصية من الصين')->lead_days);
        $this->assertSame(15, $this->type('توصية من مورد محلي')->lead_days);

        $this->get(route('accounting.sales-order-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesOrderType/Index')
                ->where('table.meta.total', 7)
                ->where('table.data.0.name', 'تحضير من المخزن')
                ->where('table.data.0.lead_days', 3));

        $this->post(route('accounting.sales-order-types.store'), ['name' => 'توصية من تركيا', 'description' => 'مورد من تركيا', 'lead_days' => 0])
            ->assertSessionHasErrors('lead_days');
        $this->post(route('accounting.sales-order-types.store'), ['name' => 'توصية من تركيا', 'description' => 'مورد من تركيا', 'lead_days' => 45, 'is_active' => true])
            ->assertSessionHas('success');

        $turkey = $this->type('توصية من تركيا');
        $this->put(route('accounting.sales-order-types.update', $turkey->id), ['name' => 'توصية من تركيا', 'lead_days' => 40, 'is_active' => true])
            ->assertSessionHas('success');
        $this->assertSame(40, $turkey->refresh()->lead_days);
    }

    public function test_the_ready_by_date_is_set_on_confirmation_and_late_orders_are_shown(): void
    {
        $china = $this->type('توصية من الصين');
        $stock = $this->type('تحضير من المخزن');

        $this->get(route('accounting.sales-orders.add'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.order_type_id', null)
                ->has('orderTypeOptions', 7)
                ->where('orderTypeOptions.0.label', 'تحضير من المخزن')
                ->where('orderTypeOptions.0.lead_days', 3));

        // مسودة: النوع محفوظ ولا موعد قبل التأكيد.
        $order = $this->draftOrder([], ['order_type_id' => $china->id]);
        $this->assertSame($china->id, (int) $order->order_type_id);
        $this->assertNull($order->ready_by_date);

        // التأكيد: الموعد = تاريخ التأكيد + مهلة النوع.
        $order = app(SalesOrderService::class)->confirm($order);
        $this->assertSame(now()->addDays(90)->toDateString(), $order->ready_by_date->toDateString());

        // تغيير النوع بعد التأكيد يعيد حساب الموعد من تاريخ التأكيد نفسه.
        $form = $this->orderData([$this->weightLine()], ['order_type_id' => $stock->id]);
        app(SalesOrderService::class)->saveForm($order, $form);
        $this->assertSame(now()->addDays(3)->toDateString(), $order->refresh()->ready_by_date->toDateString());

        $this->get(route('accounting.sales-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.data.0.order_type', 'تحضير من المخزن')
                ->where('table.data.0.ready_by', now()->addDays(3)->format('d/m/Y'))
                ->where('table.data.0.late', false)
                ->has('orderTypeOptions', 7));

        // بعد المهلة: متأخرة، وتظهر في فلتر المتأخرة وفلتر نوعها.
        $this->travel(4)->days();
        $late = ['filter' => ['readiness_state' => 'late']];
        $this->get(route('accounting.sales-orders.index', $late))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.late', true));
        $this->get(route('accounting.sales-orders.index', ['filter' => ['order_type' => $china->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));

        // المفوترة لم تعد متأخرة.
        app(SalesOrderService::class)->markInvoiced($order->refresh());
        $this->get(route('accounting.sales-orders.index', $late))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
        $this->assertFalse(SalesOrder::query()->sole()->isLate());
    }

    public function test_the_types_page_needs_its_permissions(): void
    {
        $this->signIn(['Sales Order Type View List']);

        $this->get(route('accounting.sales-order-types.index'))->assertOk();
        $this->post(route('accounting.sales-order-types.store'), ['name' => 'x', 'lead_days' => 5])->assertForbidden();
        $this->put(route('accounting.sales-order-types.update', $this->type('توصية قطع')->id), ['name' => 'x', 'lead_days' => 5])->assertForbidden();
        $this->delete(route('accounting.sales-order-types.destroy', $this->type('توصية قطع')->id))->assertForbidden();
    }
}
