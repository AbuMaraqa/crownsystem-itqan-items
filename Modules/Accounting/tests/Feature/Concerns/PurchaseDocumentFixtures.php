<?php

namespace Modules\Accounting\tests\Feature\Concerns;

use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;

/**
 * بيئة صفحات مستندات الشراء في لوحة Vue: عملة اساسية، سنة وفترة مفتوحتان، دليل حسابات وربطه،
 * وسيناريوهات الشراء التجريبية (DemoPurchasingSeeder)، ومستخدم بالصلاحيات المطلوبة.
 */
trait PurchaseDocumentFixtures
{

    /** Every permission of the purchase documents' pages and actions. */
    protected const PURCHASE_PERMISSIONS = [
        'Purchase Quotation View List', 'Purchase Quotation Create', 'Purchase Quotation Update', 'Purchase Quotation Delete',
        'Purchase Quotation Approve', 'Purchase Quotation Cancel',
        'Purchase Order View List', 'Purchase Order Create', 'Purchase Order Update', 'Purchase Order Delete', 'Purchase Order View',
        'Purchase Order Info', 'Purchase Order Confirm', 'Purchase Order Receive', 'Purchase Order Cancel',
        'Purchase Invoice View List', 'Purchase Invoice Create', 'Purchase Invoice Update', 'Purchase Invoice Delete', 'Purchase Invoice View',
        'Purchase Invoice Info', 'Purchase Invoice Post', 'Purchase Invoice Cancel',
        'Purchase Return View List', 'Purchase Return Create', 'Purchase Return Update', 'Purchase Return Delete', 'Purchase Return View',
        'Purchase Return Info', 'Purchase Return Post', 'Purchase Return Cancel',
        'Payment Allocation Create', 'Payment Allocation Delete',
    ];

    protected Currency $currency;

    protected function bootPurchaseDocuments(bool $demo = true): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->withoutVite();
        app()->setLocale('ar');

        foreach (self::PURCHASE_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->signInWith(self::PURCHASE_PERMISSIONS);

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

        if ($demo) {
            $this->seed(DemoPurchasingSeeder::class);
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function signInWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    protected function supplier(): User
    {
        return User::query()->where('name', 'مورد تجريبي - شركة الألمنيوم')->firstOrFail();
    }

    protected function warehouse(): Warehouse
    {
        return Warehouse::query()->orderBy('id')->firstOrFail();
    }

    protected function demoProduct(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    protected function demoOrder(string $scenario): PurchaseOrder
    {
        return PurchaseOrder::query()->where('notes', DemoPurchasingSeeder::NOTE_PREFIX.$scenario)->firstOrFail();
    }

    protected function fullCycleInvoice(): PurchaseInvoice
    {
        return $this->demoOrder('Full cycle')->purchaseInvoices()->firstOrFail();
    }

    protected function onHand(Product $product): float
    {
        return round(app(StockLedgerService::class)->onHand($product->id, $this->warehouse()->id), 4);
    }

    /**
     * سطر وزن يدوي: 3 قطع × 6 م × 0.9 = 16.2 كغم، بوزن فعلي 16.2 × 10.
     *
     * @return array<string, mixed>
     */
    protected function weightLine(float $actual = 16.2, float $price = 10): array
    {
        return [
            'product_id' => null,
            'category_finish_id' => $this->demoProduct('DEMO-1025-02')->category_finish_id,
            'pricing_type' => 'weight',
            'quantity' => 1,
            'unit_price' => $price,
            'actual_weight' => $actual,
            'pieces' => [['product_id' => $this->demoProduct('DEMO-1025-02')->id, 'piece_count' => 3, 'piece_length' => 6, 'weight_per_meter' => 0.9]],
        ];
    }
}
