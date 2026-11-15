<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PurchaseReturnService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Services\SalesQuotationService;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * جدول الاصناف الموحد في كل المستندات: الصنف الرئيسي واصنافه الفرعية في عروض البيع
 * وفواتيره ومرتجعاته، ومرتجعات الشراء وعروض اسعار الشراء، وحركة المخزون لكل صنف فرعي.
 */
class UnifiedItemsGridTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'Sales Invoice View List', 'Sales Invoice Update', 'Sales Invoice Post', 'Sales Quotation Update', 'Sales Quotation Approve',
            'Sales Return Update', 'Sales Return Post', 'Sales Return Cancel',
            'Purchase Return Update', 'Purchase Return Post', 'Purchase Return Cancel',
            'Purchase Quotation Update', 'Purchase Quotation View List',
        ];
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        Currency::create([
            'code' => 'ILS', 'symbol' => '₪', 'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => 1, 'is_active' => true, 'is_default' => true,
        ]);
        $year = FiscalYear::create([
            'name' => 'FY', 'starts_at' => now()->startOfYear()->toDateString(), 'ends_at' => now()->endOfYear()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(), 'is_default' => true,
        ]);
        AccountingPeriod::create([
            'fiscal_year_id' => $year->id, 'name' => 'P', 'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(), 'status' => AccountingPeriodStatus::OPEN->value(),
        ]);
        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
        $this->seed(DemoPurchasingSeeder::class);

        $this->customer = User::create(['name' => 'زبون تجريبي', 'phone' => '0599000000', 'password' => bcrypt('secret-password')]);
        $this->customer->assignRole(Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'web']));
    }

    public function test_a_manual_sales_invoice_is_priced_by_its_sub_items_and_issues_each_from_stock(): void
    {
        $sub = $this->product('DEMO-1025-01');
        $before = $this->onHand($sub);

        $invoice = $this->postedManualInvoice(2);

        // قطعتان × 6 م × 1.2 = 14.4 كغم × 20.
        $line = $invoice->items()->with('pieces')->firstOrFail();
        $this->assertSame(14.4, (float) $line->quantity);
        $this->assertSame(288.0, (float) $line->line_total);
        $this->assertCount(1, $line->pieces);
        $this->assertSame($before - 2, $this->onHand($sub));
    }

    public function test_the_sales_invoice_page_shows_the_grid_and_keeps_sub_item_ids_on_save(): void
    {
        $invoice = app(SalesInvoiceService::class)->create($this->manualInvoiceData(3));
        $pieceId = $invoice->items()->firstOrFail()->pieces()->value('id');

        $this->salesPages();
        $form = $this->get(route('accounting.sales-invoices.edit', $invoice->id))->assertOk()->viewData('page')['props']['invoice'];
        $main = collect($form['items'])->firstWhere('pricing_type', 'weight');

        $this->assertCount(1, $main['pieces']);
        $this->assertSame($pieceId, $main['pieces'][0]['id']);

        $this->put(route('accounting.sales-invoices.update', $invoice->id), $form)->assertSessionHas('success');

        // القطعة تُحدَّث في مكانها، فيبقى معرفها كما هو لمن يرتبط بها.
        $this->assertSame($pieceId, $invoice->refresh()->items()->firstOrFail()->pieces()->value('id'));
    }

    public function test_quotation_sub_items_move_to_the_sales_order(): void
    {
        // العرض يُنشأ برأسه فقط (صفحة الاضافة) ثم تُضاف سطوره في صفحة التعديل.
        $service = app(SalesQuotationService::class);
        $header = [
            'client_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'currency_id' => Currency::query()->value('id'),
        ];
        $quotation = $service->update($service->create($header), $header + ['items' => [$this->weightLine(4, 20)]]);

        $line = $quotation->items()->with('pieces')->firstOrFail();
        $this->assertSame(28.8, (float) $line->quantity);
        $this->assertCount(1, $line->pieces);

        // صفحة العرض (Inertia) تفتح بسطره وقطعه.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->get(route('accounting.sales-quotations.edit', $quotation->id))->assertOk();

        $quotation = app(SalesQuotationService::class)->approve($quotation);
        $order = app(SalesOrderService::class)->createFromQuotation($quotation);
        $orderLine = $order->items()->with('pieces')->firstOrFail();

        $this->assertCount(1, $orderLine->pieces);
        $this->assertSame(28.8, (float) $orderLine->total_weight);
        $this->assertSame(576.0, (float) $orderLine->line_total);
    }

    public function test_a_purchase_return_returns_sub_item_pieces_within_the_invoiced_count(): void
    {
        $invoice = $this->fullCycleInvoice();
        $sub = $this->product('DEMO-1025-01');
        $before = $this->onHand($sub);
        $service = app(PurchaseReturnService::class);

        $return = $service->post($service->create([
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [$this->returnLine($invoice, 'purchase_invoice_item_id', 'purchase_invoice_item_piece_id', ['DEMO-1025-01' => 4])],
        ]));

        // 4 قطع × 6 م × 1.2 = 28.8 كغم، وتخرج القطع الاربع من المخزن بصنفها الفرعي.
        $line = $return->items()->with('pieces')->firstOrFail();
        $this->assertSame(28.8, (float) $line->quantity);
        $this->assertSame(4.0, (float) $line->pieces->first()->piece_count);
        $this->assertSame($before - 4, $this->onHand($sub));

        // فوترت 10 قطع وأُرجعت 4: سبع قطع اخرى تتجاوز الست الباقية.
        $this->expectException(DomainException::class);
        $service->create([
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [$this->returnLine($invoice, 'purchase_invoice_item_id', 'purchase_invoice_item_piece_id', ['DEMO-1025-01' => 7])],
        ]);
    }

    public function test_a_sales_return_puts_sub_items_back_in_stock_and_reverses_their_cost(): void
    {
        $invoice = $this->postedManualInvoice(3);
        $sub = $this->product('DEMO-1025-01');
        $afterSale = $this->onHand($sub);
        $service = app(SalesReturnService::class);

        $return = $service->create([
            'sales_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [$this->returnLine($invoice, 'sales_invoice_item_id', 'sales_invoice_item_piece_id', ['DEMO-1025-01' => 1])],
        ]);

        // قطعة من ثلاث: 7.2 كغم × 20.
        $this->assertSame(144.0, (float) $return->total_amount);

        $return = $service->post($return);
        $this->assertSame($afterSale + 1, $this->onHand($sub));

        $inventoryEntry = JournalEntry::query()
            ->where('sourceable_type', $return::class)
            ->where('sourceable_id', $return->id)
            ->where('source_module', InventoryAccounts::SOURCE_MODULE)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::POSTED, $inventoryEntry->status);
        $this->assertGreaterThan(0, (float) $return->items()->firstOrFail()->unit_cost);

        $service->cancel($return);
        $this->assertSame($afterSale, $this->onHand($sub));
        $this->assertSame(JournalEntryStatus::VOIDED, $inventoryEntry->refresh()->status);
    }

    public function test_return_pages_show_returnable_pieces(): void
    {
        $salesInvoice = $this->postedManualInvoice(3);
        $salesReturn = app(SalesReturnService::class)->create(['sales_invoice_id' => $salesInvoice->id, 'return_date' => now()->toDateString()]);

        $this->salesPages();
        $salesItems = $this->get(route('accounting.sales-returns.edit', $salesReturn->id))->assertOk()->viewData('page')['props']['salesReturn']['items'];
        $row = collect($salesItems)->firstWhere('pricing_type', 'weight');
        $this->assertSame(3.0, (float) $row['pieces'][0]['max_piece_count']);
        $this->assertSame(3.0, (float) $row['pieces'][0]['piece_count']);

        $purchaseInvoice = $this->fullCycleInvoice();
        $purchaseReturn = app(PurchaseReturnService::class)->create(['purchase_invoice_id' => $purchaseInvoice->id, 'return_date' => now()->toDateString()]);

        $purchaseItems = $this->get(route('accounting.purchase-returns.edit', $purchaseReturn->id))->assertOk()->viewData('page')['props']['purchaseReturn']['items'];
        $row = collect($purchaseItems)->firstWhere('pricing_type', 'weight');
        $this->assertCount(2, $row['pieces']);

        // الحفظ من الصفحة بقطعة واحدة من كل صنف فرعي.
        $items = collect($purchaseItems)->map(function (array $item) {
            $item['quantity'] = $item['pricing_type'] === 'weight' ? $item['quantity'] : 0;
            $item['pieces'] = collect($item['pieces'])->map(fn (array $piece) => array_merge($piece, ['piece_count' => 1]))->all();

            return $item;
        })->all();

        $this->put(route('accounting.purchase-returns.update', $purchaseReturn->id), ['return_date' => now()->toDateString(), 'items' => $items])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertSame(2.0, (float) $purchaseReturn->refresh()->items()->firstOrFail()->pieces()->sum('piece_count'));
    }

    public function test_the_purchase_quotation_shows_the_order_sub_items(): void
    {
        $quotation = PurchaseOrder::query()->where('notes', DemoPurchasingSeeder::NOTE_PREFIX.'Partial receipt')->firstOrFail()->purchaseQuotations()->firstOrFail();

        $this->salesPages();
        $lines = $this->get(route('accounting.purchase-quotations.edit', $quotation->id))->assertOk()->viewData('page')['props']['quotation']['items'];
        $main = collect($lines)->firstWhere('pricing_type', 'weight');

        // 20 قطعة × 6.5 م × 1.5 = 195 كغم.
        $this->assertCount(1, $main['pieces']);
        $this->assertSame(195.0, (float) $main['quantity']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * صفحات البيع في لوحة Vue تُطلب مباشرة دون تحويل اللغة.
     */
    private function salesPages(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
    }

    private function postedManualInvoice(int $pieces): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->create($this->manualInvoiceData($pieces)));
    }

    private function manualInvoiceData(int $pieces): array
    {
        return [
            'client_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->value('id'),
            'invoice_date' => now()->toDateString(),
            'currency_id' => Currency::query()->value('id'),
            'items' => [$this->weightLine($pieces, 20)],
        ];
    }

    private function weightLine(int $pieces, float $price): array
    {
        return [
            'product_id' => $this->product('DEMO-1025')->id,
            'unit_price' => $price,
            'actual_weight' => round($pieces * 6 * 1.2, 4),
            'pieces' => [['product_id' => $this->product('DEMO-1025-01')->id, 'piece_count' => $pieces, 'piece_length' => 6, 'weight_per_meter' => 1.2]],
        ];
    }

    /**
     * سطر مرتجع لصنف الوزن في الفاتورة بعدد قطع لكل صنف فرعي (بالرمز).
     *
     * @param  array<string, int>  $counts
     */
    private function returnLine(PurchaseInvoice|SalesInvoice $invoice, string $itemKey, string $pieceKey, array $counts): array
    {
        $line = $invoice->items()->with('pieces.product')->get()->first(fn ($item) => $item->pieces->isNotEmpty());

        return [
            $itemKey => $line->id,
            'pieces' => $line->pieces->map(fn ($piece) => [
                $pieceKey => $piece->id,
                'piece_count' => $counts[$piece->product->sku] ?? 0,
            ])->all(),
        ];
    }

    private function fullCycleInvoice(): PurchaseInvoice
    {
        return PurchaseOrder::query()->where('notes', DemoPurchasingSeeder::NOTE_PREFIX.'Full cycle')->firstOrFail()->purchaseInvoices()->firstOrFail();
    }

    private function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    private function onHand(Product $product): float
    {
        return round(app(StockLedgerService::class)->onHand($product->id, Warehouse::query()->value('id')), 4);
    }
}
