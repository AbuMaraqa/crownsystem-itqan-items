<?php

namespace Modules\Accounting\tests\Feature\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ما تشترك فيه اختبارات صفحات مستندات البيع في لوحة Vue: الصلاحيات، السنة المالية والحسابات
 * والاصناف التجريبية (صنف رئيسي DEMO-1025 باصنافه الفرعية ورصيدها)، وسطور جدول الاصناف كما
 * يرسلها Vue.
 */
trait SalesDocumentFixtures
{

    protected const SALES_PERMISSIONS = [
        'Sales Order View List', 'Sales Order View', 'Sales Order Info', 'Sales Order Create', 'Sales Order Update',
        'Sales Order Delete', 'Sales Order Confirm', 'Sales Order Prepare', 'Sales Order Cancel',
        'Sales Invoice View List', 'Sales Invoice View', 'Sales Invoice Info', 'Sales Invoice Create', 'Sales Invoice Update',
        'Sales Invoice Delete', 'Sales Invoice Post', 'Sales Invoice Cancel',
        'Sales Return View List', 'Sales Return View', 'Sales Return Info', 'Sales Return Create', 'Sales Return Update',
        'Sales Return Delete', 'Sales Return Post', 'Sales Return Cancel',
        'Sales Quotation Update', 'Payment Allocation Create', 'Payment Allocation Delete',
        // يُبلَّغ اصحابها بطلبية التوريد المباشر (PurchasingNotifier).
        'Purchase Order Create',
    ];

    protected User $customer;

    protected Currency $currency;

    protected Warehouse $warehouse;

    protected function setUpSalesDocuments(): void
    {
        // حفظ المستند يرسل اشعاراً مباشراً، ولا خادم بث في الاختبارات.
        config(['broadcasting.default' => 'null']);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::SALES_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->signIn();

        $this->currency = Currency::create([
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

        $this->warehouse = Warehouse::query()->firstOrFail();
        $this->customer = User::factory()->create(['name' => 'زبون المبيعات']);
        $this->customer->assignRole(Role::findOrCreate('Customer', 'web'));
    }

    /**
     * @param  list<string>|null  $permissions
     */
    protected function signIn(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?? self::SALES_PERMISSIONS);
        $this->actingAs($user);

        return $user;
    }

    protected function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    protected function onHand(Product $product): float
    {
        return (float) StockBalance::query()->where('product_id', $product->id)->sum('quantity');
    }

    /**
     * سطر مجموعة سعر بقطع ألوانها كما يرسله جدول Vue: قطع × 6 م × 1.2 كغم/م، بوزن فعلي = النظري.
     *
     * @return array<string, mixed>
     */
    protected function weightLine(int $pieces = 2, float $price = 20, ?float $actual = null): array
    {
        return [
            '_key' => 'row-'.Str::random(5),
            'row_type' => 'item',
            'product_id' => null,
            'category_finish_id' => $this->product('DEMO-1025-01')->category_finish_id,
            'product_label' => 'بدون دهان 1025',
            'pricing_type' => 'weight',
            'quantity' => 1,
            'actual_weight' => $actual ?? round($pieces * 6 * 1.2, 4),
            'unit_price' => $price,
            'discount_mode' => 'rate',
            'discount_rate' => 0,
            'discount_amount' => 0,
            'collapsed' => false,
            'pieces' => [[
                '_key' => 'piece-'.Str::random(5),
                'product_id' => $this->product('DEMO-1025-01')->id,
                'product_label' => 'ثابت 3 مجاري 1025',
                'piece_count' => $pieces,
                'piece_length' => 6,
                'weight_per_meter' => 1.2,
            ]],
        ];
    }

    /**
     * خدمة بالكيلوغرام بتكلفة داخلية (معالجة خام الزبون)؛ تكلفتها تُكتب في سطر المستند.
     */
    protected function serviceProduct(): Product
    {
        $service = $this->newProduct('معالجة الخام');
        $service->update(['item_type' => 'service', 'service_kind' => 'general', 'service_cost_source' => 'internal', 'pricing_type' => 'weight', 'base_price' => 0.2]);

        return $service;
    }

    protected function newProduct(string $name): Product
    {
        $product = new Product([
            'slug' => 'test-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'base_price' => 10,
            'sale_price' => 10,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serviceLine(Product $service, float $quantity = 500, float $price = 0.2, float $cost = 0.08): array
    {
        return [
            '_key' => 'service-'.Str::random(5),
            'product_id' => $service->id,
            'product_label' => 'معالجة الخام',
            'description' => 'معالجة الخام',
            'pricing_type' => 'simple',
            'pricing_basis' => 'weight',
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount_mode' => 'rate',
            'discount_rate' => 0,
            'discount_amount' => 0,
            'cost_source' => 'internal',
            'service_provider_id' => null,
            'unit_cost' => $cost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderData(array $items = [], array $overrides = []): array
    {
        return array_replace([
            'sales_quotation_id' => null,
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_type' => 'warehouse',
            'direct_supplier_id' => null,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => null,
            'currency_id' => $this->currency->id,
            'tax_id' => null,
            'order_discount' => 0,
            'notes' => 'ملاحظة الطلبية',
            'items' => $items,
            'services' => [],
            'customer_materials' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceData(array $items = [], array $overrides = []): array
    {
        return array_replace([
            'sales_order_id' => null,
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => null,
            'currency_id' => $this->currency->id,
            'tax_id' => null,
            'invoice_discount' => 0,
            'notes' => null,
            'items' => $items,
            'services' => [],
        ], $overrides);
    }

    protected function draftOrder(array $items = [], array $overrides = []): SalesOrder
    {
        return app(SalesOrderService::class)->saveForm(null, $this->orderData($items ?: [$this->weightLine()], $overrides));
    }

    protected function postedInvoice(int $pieces = 3): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->saveForm(null, $this->invoiceData([$this->weightLine($pieces)])));
    }

    /**
     * Props of an Inertia page.
     *
     * @return array<string, mixed>
     */
    protected function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
