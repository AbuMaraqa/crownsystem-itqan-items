<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شاشات الالغاء: فاتورة المبيعات وسند القبض يُلغيان من صفحتيهما بالصلاحية فقط، مع سبب
 * الالغاء، ويظهر شريط «أُلغي هذا المستند»؛ وسند الشيك القائم لا يُعرض له زر الالغاء.
 */
class SettlementPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'Sales Invoice View List', 'Sales Invoice Update', 'Sales Invoice Post', 'Sales Invoice Cancel',
        'Receipt Voucher Update', 'Receipt Voucher Post', 'Receipt Voucher Cancel',
    ];

    private Currency $currency;

    private Warehouse $warehouse;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();


        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->actingAs(User::factory()->create());
        $this->customer = User::factory()->create(['name' => 'زبون']);
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

    public function test_sales_invoice_page_cancels_a_posted_invoice_with_its_reason(): void
    {
        $this->withoutLocaleRedirect();
        $invoice = $this->postedInvoice();
        $this->actingAsUserWith(['Sales Invoice View List', 'Sales Invoice Update', 'Sales Invoice Post', 'Sales Invoice Cancel']);

        $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('record.can_cancel', true));
        $this->post(route('accounting.sales-invoices.cancel', $invoice->id), ['reason' => 'سعر خاطئ'])->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(SalesInvoiceStatus::CANCELLED, $invoice->status);
        $this->assertSame('سعر خاطئ', $invoice->cancellation_reason);

        $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('record.cancelled', true)
                ->where('record.cancelled_at', $invoice->cancelled_at->format('d/m/Y H:i'))
                ->where('record.can_cancel', false));
    }

    public function test_sales_invoice_cancel_requires_the_cancel_permission(): void
    {
        $this->withoutLocaleRedirect();
        $invoice = $this->postedInvoice();
        $this->actingAsUserWith(['Sales Invoice View List', 'Sales Invoice Update', 'Sales Invoice Post']);

        $this->post(route('accounting.sales-invoices.cancel', $invoice->id), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(SalesInvoiceStatus::POSTED, $invoice->refresh()->status);
    }

    /**
     * خطأ تحقق في صفحة مستند ذات جدول اصناف يعود رسائل حقول، ولا يُغيّر المستند.
     */
    public function test_a_validation_error_on_a_grid_page_returns_field_errors_instead_of_crashing(): void
    {
        $this->withoutLocaleRedirect();
        $product = $this->stockedProduct();
        $draft = app(SalesInvoiceService::class)->create([
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]],
        ]);
        $this->actingAsUserWith(['Sales Invoice View List', 'Sales Invoice Update']);

        $form = $this->get(route('accounting.sales-invoices.edit', $draft->id))->viewData('page')['props']['invoice'];
        $form['invoice_date'] = null;

        $this->put(route('accounting.sales-invoices.update', $draft->id), $form)->assertSessionHasErrors(['invoice_date']);

        $this->assertSame(SalesInvoiceStatus::DRAFT, $draft->refresh()->status);
    }

    public function test_receipt_voucher_page_cancels_a_cash_voucher_but_not_one_with_an_open_cheque(): void
    {
        $this->withoutLocaleRedirect();
        $this->actingAsUserWith(['Receipt Voucher Update', 'Receipt Voucher Post', 'Receipt Voucher Cancel']);

        $cash = $this->postedReceipt();

        $this->get(route('accounting.receipt-vouchers.edit', $cash->id))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('record.can_cancel', true));
        $this->post(route('accounting.receipt-vouchers.cancel', $cash->id))->assertSessionHas('success');

        $this->assertSame(ReceiptVoucherStatus::CANCELLED, $cash->refresh()->status);

        $cheque = $this->postedReceipt([
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'CHQ-9',
            'cheque_date' => now()->toDateString(),
        ]);

        $this->get(route('accounting.receipt-vouchers.edit', $cheque->id))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('record.can_cancel', false));
        $this->post(route('accounting.receipt-vouchers.cancel', $cheque->id))->assertSessionHas('error');

        // الخدمة نفسها ترفض: الشيك القائم يُلغى من سجل الشيكات.
        $this->assertSame(ReceiptVoucherStatus::POSTED, $cheque->refresh()->status);
    }

    public function test_receipt_voucher_cancel_requires_the_cancel_permission(): void
    {
        $this->withoutLocaleRedirect();
        $this->actingAsUserWith(['Receipt Voucher Update', 'Receipt Voucher Post']);
        $voucher = $this->postedReceipt();

        $this->post(route('accounting.receipt-vouchers.cancel', $voucher->id))->assertForbidden();

        $this->assertSame(ReceiptVoucherStatus::POSTED, $voucher->refresh()->status);
    }

    /**
     * صفحات Inertia تُطلب مباشرة دون تحويل اللغة.
     */
    private function withoutLocaleRedirect(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function actingAsUserWith(array $permissions): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    private function postedInvoice(): SalesInvoice
    {
        $product = $this->stockedProduct();
        $service = app(SalesInvoiceService::class);

        return $service->post($service->create([
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20]],
        ]));
    }

    private function stockedProduct(): Product
    {
        $product = new Product([
            'slug' => 'pg-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'PG-'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'مسكة';
        $product->save();

        $adjustments = app(StockAdjustmentService::class);
        $adjustments->post($adjustments->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 12]],
        ]));

        return $product;
    }

    private function postedReceipt(array $extra = []): ReceiptVoucher
    {
        $service = app(ReceiptVoucherService::class);

        return $service->post($service->create(array_merge([
            'client_id' => $this->customer->id,
            'account_id' => (int) Account::query()->where('code', '1110')->value('id'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => 50,
        ], $extra)));
    }
}
