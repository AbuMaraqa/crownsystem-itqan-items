<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\DeliveryCostElement;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Accounting\Enums\FinancialEntryStatus;
use Modules\Accounting\Enums\ShippingClassification;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الطلبيات التجميعية في لوحة Vue: القائمة وتغيير الحالة والحذف، الاضافة بسحب بنود من طلبية شراء
 * (والكمية ضمن المتبقي)، وصفحة التعديل بتبويباتها (الاصناف، الشحن، التأمين، التخليص، التسليم،
 * المرفقات، الملاحظات، عروض الاسعار والترسية، الدفعات والاعتمادات)، وكلٌّ بصلاحيته.
 */
class ConsolidatedPurchaseOrderPagesTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private const PERMISSIONS = ['View List', 'View', 'Create', 'Update', 'Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        foreach (self::PERMISSIONS as $action) {
            Permission::findOrCreate('Consolidated Purchase Order '.$action, 'web');
        }

        ConsolidatedTestFactory::currency();
        Storage::fake('accounting');
    }

    private function signIn(?array $actions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (string $action): string => 'Consolidated Purchase Order '.$action, $actions ?? self::PERMISSIONS));
        $this->actingAs($user);

        return $user;
    }

    /**
     * طلبية شراء ببند كمية (10 ابواب × 50).
     *
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem}
     */
    private function purchaseOrder(): array
    {
        $po = ConsolidatedTestFactory::purchaseOrder();
        $door = $po->items()->create([
            'product_id' => ConsolidatedTestFactory::product()->id,
            'description' => 'باب 2000 دفتين',
            'quantity' => 10,
            'unit_price' => 50,
            'line_total' => 500,
        ]);

        return [$po, $door];
    }

    /**
     * طلبية تجميعية محفوظة ببند الابواب كاملاً.
     */
    private function consolidated(): ConsolidatedPurchaseOrder
    {
        [, $door] = $this->purchaseOrder();
        $rows = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => [], 'purchase_order_item_id' => $door->id])->json('rows');

        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $rows])->assertRedirect();

        return ConsolidatedPurchaseOrder::query()->latest('id')->firstOrFail();
    }

    private function tab(ConsolidatedPurchaseOrder $order, string $tab, ?int $record = null, string $action = 'store'): string
    {
        return route('accounting.consolidated-purchase-orders.tabs.'.$action, array_filter(['consolidatedPurchaseOrder' => $order->id, 'tab' => $tab, 'record' => $record]));
    }

    public function test_add_page_pulls_a_partial_quantity_and_creates_the_order(): void
    {
        $this->signIn();
        [$po, $door] = $this->purchaseOrder();

        $this->get(route('accounting.consolidated-purchase-orders.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/ConsolidatedPurchaseOrder/Add')->where('order.items', [])->where('branchClosed', false));

        $this->getJson(route('accounting.consolidated-purchase-orders.picker.purchase-orders', ['q' => $po->reference_no]))->assertOk()->assertJsonPath('0.value', $po->id);

        // 4 من 10 ابواب؛ فوق المتبقي يُرفض بلا تغيير.
        $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => [], 'purchase_order_item_id' => $door->id, 'quantity' => 11])
            ->assertStatus(422)
            ->assertJsonPath('message', __('Quantity exceeds the remaining amount for this item.'));

        $rows = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => [], 'purchase_order_item_id' => $door->id, 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('message', __('Item added.'))
            ->json('rows');

        $this->assertEquals(4, $rows[0]['quantity']);
        $this->assertEquals(10, $rows[0]['limit']);

        // كمية فوق المتبقي لا تُحفظ، ولا طلبية بلا بنود.
        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => []])->assertSessionHasErrors('items');
        $rows[0]['quantity'] = 12;
        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $rows])->assertSessionHas('error');
        $this->assertSame(0, ConsolidatedPurchaseOrder::query()->count());

        $rows[0]['quantity'] = 4;
        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addMonth()->toDateString(), 'items' => $rows])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order = ConsolidatedPurchaseOrder::query()->firstOrFail();
        $this->assertSame(ConsolidatedPurchaseOrderStatus::UNCONFIRMED, $order->status);
        $this->assertSame(200.0, (float) $order->total_amount);
        $this->assertSame(4.0, (float) $order->items()->value('quantity'));

        // الباقي 6 لطلبية اخرى.
        $row = $this->postJson(route('accounting.consolidated-purchase-orders.picker.items'), ['purchase_order_id' => $po->id, 'rows' => []])->json('items.0');
        $this->assertEquals(6, $row['remaining']);
    }

    public function test_a_price_group_line_is_consolidated_whole_or_by_its_colours(): void
    {
        $this->signIn();
        ['white' => $white, 'whiteW45' => $w45, 'whiteW60' => $w60] = $this->alpineGroups();
        // 2 × W45 (16.25 كغم) + 1 × W60 (13 كغم)، 40 كغم فعلي × 5.
        $po = app(PurchaseOrderService::class)->create([
            'client_id' => User::factory()->create()->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->groupLine($white, [[$w45, 2], [$w60, 1]], 40, 5)],
        ]);
        $line = $po->items()->with('pieces')->sole();

        // سحب لون W60 وحده: سطر بمجموعته. وزن الميزان يخص السطر بقطعه كلها، فالجزء المسحوب بلا وزن
        // فعلي ومبلغه صفر حتى يُدخل وزنه (القاعدة نفسها لسطر الصنف الرئيسي).
        $rows = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), [
            'rows' => [], 'keys' => ['piece-'.$line->pieces->firstWhere('product_id', $w60->id)->id],
        ])->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['product_id']);
        $this->assertSame($white->id, $rows[0]['category_finish_id']);
        $this->assertSame('weight', $rows[0]['pricing_type']);

        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $rows])
            ->assertSessionHas('success');

        $order = ConsolidatedPurchaseOrder::query()->latest('id')->firstOrFail();
        $item = $order->items()->with('pieces')->sole();
        $this->assertNull($item->product_id);
        $this->assertSame($white->id, (int) $item->category_finish_id);
        $this->assertSame([$w60->id], $item->pieces->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals(13, (float) $item->total_weight);
        $this->assertNull($item->actual_weight);

        // الباقي لونه W45 فقط لطلبية اخرى، وسحب W60 مرة ثانية مرفوض.
        $rows = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), [
            'rows' => [], 'keys' => ['piece-'.$line->pieces->firstWhere('product_id', $w45->id)->id, 'piece-'.$line->pieces->firstWhere('product_id', $w60->id)->id],
        ])->assertOk()->json('rows');
        $this->assertSame([$w45->id], array_map('intval', array_column($rows[0]['pieces'], 'product_id')));
    }

    public function test_two_add_pages_cannot_take_the_same_pieces_and_a_cancelled_order_stays_cancelled(): void
    {
        $this->signIn();
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();
        $po = app(PurchaseOrderService::class)->create([
            'client_id' => User::factory()->create()->id,
            'order_date' => now()->toDateString(),
            'items' => [$this->groupLine($white, [[$w45, 2]], 16, 5)],
        ]);
        $line = $po->items()->with('pieces')->sole();

        // صفحتان فُتحتا معاً وسحبتا القطعة نفسها: الاولى تُحفظ، والثانية تُرفض ولو قالت انها «كمية».
        $first = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => [], 'keys' => ['piece-'.$line->pieces->first()->id]])->json('rows');
        $second = $first;
        $second[0]['pricing_type'] = 'simple';

        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $first])->assertSessionHas('success');
        $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $second])
            ->assertSessionHas('error', __('This item was already added to another consolidated purchase order.'));
        $this->assertSame(1, ConsolidatedPurchaseOrder::query()->count());

        // الملغاة لا تعود لحالة نشطة.
        $order = ConsolidatedPurchaseOrder::query()->sole();
        $status = fn (ConsolidatedPurchaseOrderStatus $to) => $this->post(route('accounting.consolidated-purchase-orders.status', $order->id), ['status' => $to->value]);
        $status(ConsolidatedPurchaseOrderStatus::CANCELLED)->assertSessionHas('success');
        $status(ConsolidatedPurchaseOrderStatus::CONFIRMED)->assertSessionHas('error');
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CANCELLED, $order->refresh()->status);
    }

    public function test_index_lists_changes_status_and_deletes(): void
    {
        $this->signIn();
        $order = $this->consolidated();

        $this->get(route('accounting.consolidated-purchase-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ConsolidatedPurchaseOrder/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $order->reference_no)
                ->where('table.data.0.total_amount', '500.00'));

        $this->post(route('accounting.consolidated-purchase-orders.status', $order), ['status' => ConsolidatedPurchaseOrderStatus::CONTAINER_LOADED->value])->assertSessionHas('success');
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CONTAINER_LOADED, $order->refresh()->status);

        $this->delete(route('accounting.consolidated-purchase-orders.destroy', $order))->assertRedirect(route('accounting.consolidated-purchase-orders.index'));
        $this->assertSoftDeleted($order);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CANCELLED, ConsolidatedPurchaseOrder::withTrashed()->find($order->id)->status);

        $this->get(route('accounting.consolidated-purchase-orders.index', ['filter' => ['trashed' => 'only']]))
            ->assertInertia(fn (Assert $page) => $page->where('table.data.0.deleted', true));
    }

    public function test_items_tab_changes_a_quantity_within_what_is_left_and_deletes_a_line(): void
    {
        $this->signIn();
        $order = $this->consolidated();
        $item = $order->items()->firstOrFail();

        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ConsolidatedPurchaseOrder/Edit')
                ->where('record.reference_no', $order->reference_no)
                ->has('items.rows', 1)
                ->where('counts.items', 1)
                ->has('activity.activities'));

        $this->put(route('accounting.consolidated-purchase-orders.items.update', ['consolidatedPurchaseOrder' => $order->id, 'item' => $item->id]), ['quantity' => 11])->assertSessionHas('error');
        $this->put(route('accounting.consolidated-purchase-orders.items.update', ['consolidatedPurchaseOrder' => $order->id, 'item' => $item->id]), ['quantity' => 3])->assertSessionHas('success');

        $this->assertSame(3.0, (float) $item->refresh()->quantity);
        $this->assertSame(150.0, (float) $order->refresh()->total_amount);

        $this->delete(route('accounting.consolidated-purchase-orders.items.destroy', ['consolidatedPurchaseOrder' => $order->id, 'item' => $item->id]))->assertSessionHas('success');
        $this->assertSame(0, $order->items()->count());
        $this->assertSame(0.0, (float) $order->refresh()->total_amount);
    }

    public function test_logistics_tabs_save_their_records_with_files(): void
    {
        $user = $this->signIn();
        $order = $this->consolidated();
        $shipping = User::factory()->create();
        $insurer = User::factory()->create();
        $clearing = User::factory()->create();
        $carrier = User::factory()->create();
        $element = DeliveryCostElement::create(['name' => 'نقل داخلي']);
        $customsCopy = ClearanceAttachmentType::create(['name' => 'بيان جمركي']);
        $category = AttachmentCategory::create(['name' => 'فواتير']);

        // الشحن: رقم الكنتينر مطلوب للكنتينر الكامل.
        $shipment = [
            'shipping_company_id' => $shipping->id,
            'shipping_type' => 3,
            'shipping_classification' => ShippingClassification::CONTAINER->value,
            'currency_id' => $order->currency_id,
            'price' => 500,
        ];
        $this->post($this->tab($order, 'shipments'), $shipment)->assertSessionHasErrors('container_number');
        $this->post($this->tab($order, 'shipments'), [...$shipment, 'container_number' => 'MSKU1234567', 'attachment' => UploadedFile::fake()->create('bl.pdf', 10)])->assertSessionHas('success');

        $saved = $order->shipments()->firstOrFail();
        $this->assertSame('MSKU1234567', $saved->container_number);
        $this->assertSame($user->id, $saved->created_by);
        Storage::disk('accounting')->assertExists($saved->attachment_path);
        $this->assertStringStartsWith('consolidated-purchase-order-shipments/', $saved->attachment_path);

        // التعديل بلا ملف جديد يُبقي الملف، وطلب الحذف يزيله.
        $this->put($this->tab($order, 'shipments', $saved->id, 'update'), [...$shipment, 'container_number' => 'MSKU7654321', 'price' => 650])->assertSessionHas('success');
        $this->assertSame($saved->attachment_path, $saved->refresh()->attachment_path);
        $this->assertSame(650.0, (float) $saved->price);
        $this->put($this->tab($order, 'shipments', $saved->id, 'update'), [...$shipment, 'container_number' => 'MSKU7654321', 'remove_attachment' => true])->assertSessionHas('success');
        $this->assertNull($saved->refresh()->attachment_path);

        $this->post($this->tab($order, 'insurances'), ['insurance_company_id' => $insurer->id, 'notes' => 'تأمين بحري'])->assertSessionHas('success');

        $this->post($this->tab($order, 'clearances'), [
            'clearance_company_id' => $clearing->id,
            'total_cost' => 780.25,
            'currency_id' => $order->currency_id,
            'copies' => [$customsCopy->id => UploadedFile::fake()->create('copy.pdf', 5)],
        ])->assertSessionHas('success');
        $clearance = $order->clearances()->firstOrFail();
        $this->assertSame(780.25, (float) $clearance->total_cost);
        $this->assertSame(1, $clearance->attachments()->count());
        $this->assertNotNull($clearance->attachments()->value('copy_path'));
        $this->assertNull($clearance->attachments()->value('original_path'));

        $this->post($this->tab($order, 'deliveries'), [
            'delivery_company_id' => $carrier->id,
            'currency_id' => $order->currency_id,
            'cost_items' => [['cost_element_id' => $element->id, 'amount' => 120, 'notes' => 'حيفا']],
        ])->assertSessionHas('success');
        $this->assertSame(120.0, (float) $order->deliveries()->firstOrFail()->costItems()->value('amount'));

        $this->post($this->tab($order, 'attachments'), ['attachment_category_id' => $category->id])->assertSessionHasErrors('file');
        $this->post($this->tab($order, 'attachments'), ['attachment_category_id' => $category->id, 'file' => UploadedFile::fake()->create('invoice.pdf', 5)])->assertSessionHas('success');
        Storage::disk('accounting')->assertExists($order->attachments()->value('file_path'));

        $this->post($this->tab($order, 'notes'), ['note' => 'متابعة مع المورد', 'reminder_date' => now()->addWeek()->toDateString()])->assertSessionHas('success');
        $note = $order->orderNotes()->firstOrFail();
        $this->put($this->tab($order, 'notes', $note->id, 'update'), ['note' => 'تم الاتصال'])->assertSessionHas('success');
        $this->assertSame('تم الاتصال', $note->refresh()->note);

        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.shipping', 1)
                ->where('counts.insurance', 1)
                ->where('counts.clearance', 1)
                ->where('counts.delivery', 1)
                ->where('counts.attachments', 1)
                ->where('counts.notes', 1)
                ->where('shipments.0.container_number', 'MSKU7654321')
                ->where('deliveries.0.total', '120.00')
                ->where('clearances.0.files.'.$customsCopy->id.'.original', null)
                ->where('attachments.0.category', 'فواتير'));

        // سجل تبويب من طلبية اخرى لا يُعدَّل من هذه.
        $other = $this->consolidated();
        $this->delete($this->tab($other, 'notes', $note->id, 'destroy'))->assertNotFound();

        $this->delete($this->tab($order, 'notes', $note->id, 'destroy'))->assertSessionHas('success');
        $this->delete($this->tab($order, 'shipments', $saved->id, 'destroy'))->assertSessionHas('success');
        $this->assertSame(0, $order->orderNotes()->count());
        $this->assertSame(0, $order->shipments()->count());
    }

    public function test_price_offers_award_and_financial_file(): void
    {
        $this->signIn();
        $order = $this->consolidated();
        $item = $order->items()->firstOrFail();
        $supplier = User::factory()->create();

        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page->where('blankOfferItems.0.consolidated_purchase_order_item_id', $item->id)->where('blankOfferItems.0.quantity', '10'));

        $this->post($this->tab($order, 'price-offers'), [
            'supplier_id' => $supplier->id,
            'currency_id' => $order->currency_id,
            'offer_date' => now()->toDateString(),
            'items' => [['consolidated_purchase_order_item_id' => $item->id, 'description' => 'باب', 'quantity' => 10, 'unit_price' => 45, 'discount_rate' => 10]],
        ])->assertSessionHas('success');

        $offer = $order->priceOffers()->firstOrFail();
        $this->assertSame(405.0, (float) $offer->total_amount);

        $this->post(route('accounting.consolidated-purchase-orders.award', ['consolidatedPurchaseOrder' => $order->id, 'offer' => $offer->id]), ['notes' => 'الارخص'])->assertSessionHas('success');
        $this->assertSame($offer->id, $order->award()->value('price_offer_id'));

        // العرض المرسى عليه لا يُحذف قبل الغاء الترسية.
        $this->delete($this->tab($order, 'price-offers', $offer->id, 'destroy'))->assertSessionHas('error');
        $this->delete(route('accounting.consolidated-purchase-orders.award.cancel', $order))->assertSessionHas('success');
        $this->delete($this->tab($order, 'price-offers', $offer->id, 'destroy'))->assertSessionHas('success');

        $this->post($this->tab($order, 'payments'), [
            'payment_type' => 1,
            'status' => FinancialEntryStatus::PENDING->value,
            'amount' => 250,
            'percentage' => 50,
            'due_date' => now()->addMonth()->toDateString(),
            'currency_id' => $order->currency_id,
        ])->assertSessionHas('success');
        $this->post($this->tab($order, 'bank-credits'), [
            'credit_type' => 3,
            'bank_name' => 'بنك فلسطين',
            'credit_amount' => 1000,
            'duration_days' => 90,
            'due_date' => now()->addMonths(3)->toDateString(),
            'status' => FinancialEntryStatus::PENDING->value,
            'currency_id' => $order->currency_id,
        ])->assertSessionHas('success');

        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.financial_file', 2)
                ->where('payments.0.amount', '250.00')
                ->where('bankCredits.0.bank_name', 'بنك فلسطين'));
    }

    public function test_header_parties_lookups_and_quote_request(): void
    {
        $this->signIn();
        $order = $this->consolidated();
        $follower = User::factory()->create(['name' => 'موظف المتابعة']);

        $this->put(route('accounting.consolidated-purchase-orders.update', $order), [
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addWeeks(2)->toDateString(),
            'followed_up_by' => $follower->id,
            'notes' => 'ملاحظة',
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame($follower->id, $order->followed_up_by);
        $this->assertSame(500.0, (float) $order->total_amount, 'Saving the header keeps the lines and totals.');

        $this->postJson(route('accounting.consolidated-purchase-orders.parties.store'), ['role' => 'shipping', 'name' => 'شركة الشحن السريع'])->assertOk()->assertJsonPath('label', 'شركة الشحن السريع');
        $this->getJson(route('accounting.consolidated-purchase-orders.parties', ['role' => 'shipping', 'q' => 'السريع']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.label', 'شركة الشحن السريع');
        $this->getJson(route('accounting.consolidated-purchase-orders.parties', ['role' => 'insurance', 'q' => 'السريع']))->assertOk()->assertJsonCount(0);
        $this->postJson(route('accounting.consolidated-purchase-orders.parties.store'), ['role' => 'admin', 'name' => 'x'])->assertStatus(422);

        $this->postJson(route('accounting.consolidated-purchase-orders.lookups.store'), ['type' => 'reappraisal-element', 'name' => 'غرامة'])->assertOk()->assertJsonPath('label', 'غرامة');
        $this->postJson(route('accounting.consolidated-purchase-orders.lookups.store'), ['type' => 'attachment-category', 'name' => 'شهادات'])->assertOk();
        $this->postJson(route('accounting.consolidated-purchase-orders.lookups.store'), ['type' => 'additional-cost-type', 'name' => 'عمولة'])->assertOk();

        // الطباعة نفسها لا تُشغَّل هنا: تحميل الواجهة PDF يجعل class_exists('pdf') صحيحاً لبقية العملية،
        // فتنكسر مسارات `'pdf'` داخل مجموعات ->controller() في الاختبارات التالية.
        $supplier = User::factory()->create();
        $this->mock(AccountingPdfService::class)
            ->shouldReceive('consolidatedPurchaseOrderQuoteRequest')
            ->once()
            ->withArgs(fn ($printed, $to): bool => $printed->is($order) && $to->is($supplier))
            ->andReturn(response('PDF'));
        $this->get(route('accounting.consolidated-purchase-orders.quote-request', ['consolidatedPurchaseOrder' => $order->id, 'supplier' => $supplier->id]))->assertOk()->assertSee('PDF');
    }

    public function test_every_action_needs_its_permission(): void
    {
        $this->signIn();
        $order = $this->consolidated();
        $item = $order->items()->firstOrFail();

        $this->signIn(['View List']);

        $this->get(route('accounting.consolidated-purchase-orders.index'))->assertOk();
        $this->get(route('accounting.consolidated-purchase-orders.add'))->assertForbidden();
        $this->post(route('accounting.consolidated-purchase-orders.store'), [])->assertForbidden();
        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))->assertForbidden();
        $this->put(route('accounting.consolidated-purchase-orders.update', $order), [])->assertForbidden();
        $this->post(route('accounting.consolidated-purchase-orders.status', $order), ['status' => 2])->assertForbidden();
        $this->delete(route('accounting.consolidated-purchase-orders.destroy', $order))->assertForbidden();
        $this->post(route('accounting.consolidated-purchase-orders.items.pull', $order), [])->assertForbidden();
        $this->delete(route('accounting.consolidated-purchase-orders.items.destroy', ['consolidatedPurchaseOrder' => $order->id, 'item' => $item->id]))->assertForbidden();
        $this->post($this->tab($order, 'notes'), ['note' => 'x'])->assertForbidden();
        $this->getJson(route('accounting.consolidated-purchase-orders.picker.purchase-orders'))->assertForbidden();
        $this->postJson(route('accounting.consolidated-purchase-orders.parties.store'), ['role' => 'shipping', 'name' => 'x'])->assertForbidden();
        $this->get(route('accounting.consolidated-purchase-orders.quote-request', ['consolidatedPurchaseOrder' => $order->id, 'supplier' => User::factory()->create()->id]))->assertForbidden();

        // الانشاء وحده يفتح نافذة السحب، لا تبويبات الطلبية.
        $this->signIn(['Create']);
        $this->getJson(route('accounting.consolidated-purchase-orders.picker.purchase-orders'))->assertOk();
        $this->post($this->tab($order, 'notes'), ['note' => 'x'])->assertForbidden();
        $this->assertSame(1, $order->items()->count());
    }
}
