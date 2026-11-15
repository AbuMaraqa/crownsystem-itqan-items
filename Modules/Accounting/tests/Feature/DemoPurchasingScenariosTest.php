<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\StockLevelService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * يشغّل بيانات الشراء التجريبية ويفحص المخزون والقيود في كل حالة.
 */
class DemoPurchasingScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
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
    }

    public function test_every_purchasing_case_leaves_the_right_stock(): void
    {
        // [قطع بالمخزن، كغم، قيد الوصول، قيمة]
        $expected = [
            // 100 بـ 4.5 (دورة كاملة) + 40 بـ 4.8 (عرض جديد بعد الملغى).
            'DEMO-HINGE' => [140, 0, 0, 642],
            // 30 بـ 7 (استلام جزئي، 20 قيد الوصول) + 10 بكلفة مؤقتة 6.5؛ الفاتورة الملغاة لا اثر لها.
            'DEMO-LOCK' => [40, 0, 20, 275],
            // طلبية عرضها مسودة: 1000 قيد الوصول؛ والمخزون من الشراء المباشر 500 بـ 0.04.
            'DEMO-SCREW' => [500, 0, 1000, 20],
            // المقطع بعدده ووزنه المحسوب وبلا قيمة.
            // 10 قطع × 6 م × 1.2 = 72 كغم + شراء مباشر 5 قطع = 36 كغم.
            'DEMO-1025-01' => [15, 108, 0, 0],
            // 8 قطع × 6 م × 0.9 = 43.2 كغم.
            'DEMO-1025-02' => [8, 43.2, 0, 0],
            // 12 من 20 قطعة (8 قيد الوصول) + فاتورة مباشرة 4 قطع.
            'DEMO-4500-01' => [16, 156, 8, 0],
            // الرئيسي بكغمه وقيمته: (72 + 43.2) كغم بـ 12 = 1382.4 + 36 كغم بـ 11.5 = 414.
            'DEMO-1025' => [23, 151.2, 0, 1796.4],
            // 12 من 20 قطعة × 9.75 = 117 كغم بـ 15 = 1755 + 39 كغم بـ 14 = 546.
            'DEMO-4500' => [16, 156, 8, 2301],
        ];

        $report = collect(app(DemoPurchasingSeeder::class)->stockReport())->keyBy(0);

        foreach ($expected as $sku => [$onHand, $weight, $incoming, $value]) {
            $this->assertSame([(float) $onHand, (float) $weight, (float) $incoming, (float) $value], [
                (float) $report[$sku][2], (float) $report[$sku][3], (float) $report[$sku][4], (float) $report[$sku][6],
            ], "Stock of {$sku}");
        }
    }

    public function test_a_main_item_shows_the_sum_of_its_sub_items(): void
    {
        $main = Product::query()->where('sku', 'DEMO-1025')->firstOrFail();
        $levels = app(StockLevelService::class)->productLevels($main->id)['levels'];

        $this->assertSame(23.0, (float) $levels['on_hand']);
        $this->assertSame(151.2, (float) $levels['weight']);
    }

    public function test_ledger_balances_match_the_cases(): void
    {
        // مستلم غير مفوتر = الاستلام الجزئي فقط: 30 × 7 + 117 × 15.
        $this->assertSame(-1965.0, $this->balance($this->account('2125')));
        // فرق السعر من الفاتورة بلا عرض: 10 × (7 - 6.5).
        $this->assertSame(5.0, $this->balance($this->account('5115')));
        // الفواتير المرحّلة: 1832.4 + 192 + 70 + 434 + 546.
        $supplier = User::query()->where('name', 'مورد تجريبي - شركة الألمنيوم')->firstOrFail();
        $this->assertSame(-3074.4, $this->balance((int) Account::query()->where('user_id', $supplier->id)->value('id')));
    }

    public function test_an_invoice_from_a_quotation_carries_the_order_sub_items(): void
    {
        $order = PurchaseOrder::query()->where('notes', DemoPurchasingSeeder::NOTE_PREFIX.'Full cycle')->firstOrFail();
        $line = $order->purchaseInvoices()->firstOrFail()->items()->with('pieces')->get()->firstWhere('product_id', Product::query()->where('sku', 'DEMO-1025')->value('id'));

        // 72 + 43.2 كغم بـ 12 للكغم.
        $this->assertCount(2, $line->pieces);
        $this->assertSame(115.2, (float) $line->quantity);
        $this->assertSame(115.2, (float) $line->total_weight);
        $this->assertSame(1382.4, (float) $line->line_total);
        $this->assertNotNull($line->pieces->first()->purchase_order_item_piece_id);
    }

    public function test_quotation_states_are_all_represented_and_reseeding_adds_nothing(): void
    {
        $draft = PurchaseOrder::query()->where('notes', DemoPurchasingSeeder::NOTE_PREFIX.'Draft quotation')->firstOrFail();
        $this->assertSame(PurchaseQuotationStatus::DRAFT, $draft->activePurchaseQuotation()->status);

        $orders = PurchaseOrder::query()->count();
        $this->seed(DemoPurchasingSeeder::class);
        $this->assertSame($orders, PurchaseOrder::query()->count());
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
