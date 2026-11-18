<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Services\PurchaseQuotationService;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * دورة الشراء: طلبية بلا اسعار ← عرض سعر شراء (عرض واحد يُعتمد) ← فاتورة شراء باسعار
 * العرض، او باسعار تُدخل في الفاتورة حين لا يوجد عرض. والمخزون يُقيَّم بسعر العرض.
 */
class PurchaseQuotationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->supplier = User::factory()->create(['name' => 'مورد']);
        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $year = FiscalYear::create([
            'name' => 'FY Test',
            'starts_at' => now()->startOfYear()->toDateString(),
            'ends_at' => now()->endOfYear()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
            'is_default' => true,
        ]);

        AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'name' => now()->format('F Y'),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
        ]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);

        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    public function test_a_purchase_order_carries_no_prices_and_confirms_without_a_total(): void
    {
        $order = $this->purchaseOrder($this->product('زاوية'), 10);

        $this->assertNull($order->currency_id);
        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertSame(0.0, (float) $order->items->first()->unit_price);

        $order = app(PurchaseOrderService::class)->confirm($order);

        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $order->status);
    }

    public function test_the_quotation_mirrors_the_order_lines_and_only_one_is_active_per_order(): void
    {
        $order = $this->purchaseOrder($this->product('حلق'), 10);
        $quotation = $this->quote($order, 25);

        $this->assertSame(PurchaseQuotationStatus::DRAFT, $quotation->status);
        $this->assertSame($this->supplier->id, $quotation->client_id);
        $this->assertCount(1, $quotation->items);
        $this->assertSame(10.0, (float) $quotation->items->first()->quantity);
        $this->assertSame(250.0, (float) $quotation->total_amount);

        $this->expectException(DomainException::class);
        app(PurchaseQuotationService::class)->createFromOrder($order);
    }

    public function test_a_cancelled_quotation_frees_the_order_for_a_new_one(): void
    {
        $order = $this->purchaseOrder($this->product('مسكة'), 4);
        $first = $this->quote($order, 10);

        app(PurchaseQuotationService::class)->cancel($first);
        $second = $this->quote($order, 12);

        $this->assertSame(PurchaseQuotationStatus::CANCELLED, $first->refresh()->status);
        $this->assertSame($second->id, $order->activePurchaseQuotation()->id);
    }

    public function test_a_draft_quotation_follows_edits_of_the_order(): void
    {
        $product = $this->product('كبس');
        $order = $this->purchaseOrder($product, 5);
        $quotation = $this->quote($order, 10);

        $item = $order->items->first();
        app(PurchaseOrderService::class)->update($order, $this->orderData([
            ['id' => $item->id, 'product_id' => $product->id, 'quantity' => 8],
        ]));

        $quotation = app(PurchaseQuotationService::class)->update($quotation, $this->quotationHeader());

        $this->assertSame(8.0, (float) $quotation->items->first()->quantity);
        $this->assertSame(10.0, (float) $quotation->items->first()->unit_price);
        $this->assertSame(80.0, (float) $quotation->total_amount);
    }

    public function test_approval_requires_a_price_for_every_item(): void
    {
        $order = $this->purchaseOrder($this->product('برغي'), 10);
        $quotation = $this->quote($order, 0);

        $this->expectException(DomainException::class);
        app(PurchaseQuotationService::class)->approve($quotation);
    }

    public function test_a_draft_quotation_blocks_invoicing_the_order(): void
    {
        $order = $this->purchaseOrder($this->product('زجاج'), 2);
        $this->quote($order, 30);

        $this->expectException(DomainException::class);
        app(PurchaseInvoiceService::class)->createFromOrder($order);
    }

    public function test_full_cycle_receipt_and_invoice_at_the_quoted_price_leave_no_variance(): void
    {
        $order = $this->purchaseOrder($this->product('قاطع'), 10);
        $quotation = app(PurchaseQuotationService::class)->approve($this->quote($order, 25, 10));

        // كلفة الاستلام = سعر العرض بعد خصم السطر: 10 × 25 × 90% = 225.
        $receipt = $this->postReceipt($order, 10);
        $this->assertSame(225.0, round((float) $receipt->total_cost, 4));

        $service = app(PurchaseInvoiceService::class);
        $invoice = $service->createFromQuotation($quotation);

        $this->assertSame($quotation->id, $invoice->purchase_quotation_id);
        $this->assertSame(PurchaseQuotationStatus::INVOICED, $quotation->refresh()->status);
        $this->assertSame(25.0, (float) $invoice->items->first()->unit_price);
        $this->assertSame(225.0, (float) $invoice->total_amount);

        $invoice = $service->post($invoice);

        $this->assertSame(PurchaseInvoiceStatus::POSTED, $invoice->status);
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(0.0, $this->balance($this->account('5115')));
        $this->assertSame(225.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-225.0, $this->balance($this->supplierAccount()));
    }

    public function test_invoicing_without_a_quotation_starts_unpriced_and_cannot_post_until_priced(): void
    {
        $product = $this->product('شباك');
        ConsolidatedTestFactory::averageCost($product, 8);
        $order = $this->purchaseOrder($product, 10);
        $service = app(PurchaseInvoiceService::class);

        $invoice = $service->createFromOrder($order);
        $this->assertNull($invoice->purchase_quotation_id);
        $this->assertSame(0.0, (float) $invoice->items->first()->unit_price);

        try {
            $service->post($invoice);
            $this->fail('An unpriced purchase invoice must not post.');
        } catch (DomainException) {
            $this->assertSame(PurchaseInvoiceStatus::DRAFT, $invoice->refresh()->status);
        }

        $item = $invoice->items->first();
        $invoice = $service->update($invoice, [
            'purchase_order_id' => $order->id,
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['id' => $item->id, 'purchase_order_item_id' => $item->purchase_order_item_id, 'product_id' => $product->id, 'quantity' => 10, 'unit_price' => 9]],
        ]);
        $invoice = $service->post($invoice);

        // البضاعة دخلت بكلفة مؤقتة (متوسط تكلفة الصنف من مشترياته 8)، والفاتورة بسعر 9: الفرق 10 فرق سعر.
        $this->assertSame(PurchaseInvoiceStatus::POSTED, $invoice->status);
        $this->assertSame(80.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(10.0, $this->balance($this->account('5115')));
    }

    public function test_cancelling_the_invoice_returns_the_quotation_to_approved(): void
    {
        $order = $this->purchaseOrder($this->product('مفصلة'), 3);
        $quotation = app(PurchaseQuotationService::class)->approve($this->quote($order, 20));
        $service = app(PurchaseInvoiceService::class);
        $invoice = $service->post($service->createFromQuotation($quotation));

        try {
            app(PurchaseQuotationService::class)->cancel($quotation->refresh());
            $this->fail('An invoiced quotation must not be cancelled.');
        } catch (DomainException) {
            $this->assertSame(PurchaseQuotationStatus::INVOICED, $quotation->refresh()->status);
        }

        $service->cancel($invoice);

        $this->assertSame(PurchaseQuotationStatus::APPROVED, $quotation->refresh()->status);
        $this->assertTrue($order->refresh()->canCreateInvoice());
    }

    public function test_deleting_a_draft_invoice_returns_the_quotation_to_approved(): void
    {
        $order = $this->purchaseOrder($this->product('قفل'), 2);
        $quotation = app(PurchaseQuotationService::class)->approve($this->quote($order, 15));
        $service = app(PurchaseInvoiceService::class);

        $service->delete($service->createFromQuotation($quotation));

        $this->assertSame(PurchaseQuotationStatus::APPROVED, $quotation->refresh()->status);
    }

    // ------------------------------------------------------------ helpers

    private function product(string $name): Product
    {
        $product = new Product([
            'slug' => 'pq-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'PQ-'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function purchaseOrder(Product $product, float $quantity): PurchaseOrder
    {
        $order = app(PurchaseOrderService::class)->create($this->orderData([
            ['product_id' => $product->id, 'quantity' => $quantity],
        ]));

        return app(PurchaseOrderService::class)->confirm($order);
    }

    private function orderData(array $items): array
    {
        return [
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => $items,
        ];
    }

    private function quotationHeader(): array
    {
        return [
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
        ];
    }

    private function quote(PurchaseOrder $order, float $price, float $discountRate = 0): PurchaseQuotation
    {
        $items = $order->items()->get()->map(fn ($item) => [
            'purchase_order_item_id' => $item->id,
            'unit_price' => $price,
            'discount_rate' => $discountRate,
        ])->all();

        return app(PurchaseQuotationService::class)->createFromOrder($order, array_merge($this->quotationHeader(), ['items' => $items]));
    }

    private function postReceipt(PurchaseOrder $order, float $quantity): GoodsReceipt
    {
        $service = app(GoodsReceiptService::class);

        return $service->post($service->create([
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => $quantity]],
        ]));
    }

    private function supplierAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->supplier->id)->value('id');
    }

    private function warehouseAccount(): int
    {
        return (int) app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function account(string $code): int
    {
        app(InventoryAccounts::class)->ensureDefaults();

        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function balance(int $accountId): float
    {
        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()]))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }
}
