<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Services\PurchaseQuotationService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockLevelService;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Role;

/**
 * بيانات تجريبية لدورة الشراء كاملة، تمر كلها عبر الخدمات الحقيقية فتنطبق القواعد المحاسبية:
 *
 * اصناف بكل الانواع: اصناف بالكمية، اصناف وزن رئيسية (وهمية) باصناف فرعية، وخدمة.
 * حالات الشراء:
 *   1. دورة كاملة: طلبية ← عرض سعر معتمد ← استلام كامل ← فاتورة من العرض.
 *   2. استلام جزئي: عرض معتمد واستلام جزء من الكميات، بلا فاتورة بعد.
 *   3. عرض سعر مسودة ما زال قيد التفاوض: لا مخزون.
 *   4. عرض ملغى ثم عرض جديد معتمد، استلام وفاتورة.
 *   5. فاتورة بلا عرض سعر: استلام بكلفة مؤقتة ثم فاتورة بسعر مختلف ← فرق سعر.
 *   6. شراء مباشر بفاتورة بلا طلبية (كمية ووزن).
 *   7. فاتورة شراء مرحّلة ثم ملغاة: المخزون يعود كما كان.
 *   8. فاتورة مباشرة لصنف رئيسي باصنافه الفرعية: يدخل كل صنف فرعي بقطعه ووزنه.
 *
 * الاصناف تبدأ رموزها بـ DEMO-، والطلبيات تُعرف بملاحظاتها، فاعادة التشغيل لا تكرر شيئا.
 *
 * التشغيل: php artisan db:seed --class="Modules\Accounting\database\seeders\DemoPurchasingSeeder"
 */
class DemoPurchasingSeeder extends Seeder
{
    public const NOTE_PREFIX = '[DEMO] ';

    private User $supplier;

    private Warehouse $warehouse;

    private int $currencyId;

    /** @var array<string, Product> */
    private array $products = [];

    public function run(): void
    {
        if (! Auth::check() && $admin = User::role('Super Admin')->first() ?? User::query()->first()) {
            Auth::setUser($admin);
        }

        $this->currencyId = (int) app(CurrencyExchangeRateService::class)->baseCurrencyId();
        $this->warehouse = $this->warehouse();
        $this->supplier = $this->supplier();
        $this->products();

        $this->scenario('Full cycle', fn () => $this->fullCycle());
        $this->scenario('Partial receipt', fn () => $this->partialReceipt());
        $this->scenario('Draft quotation', fn () => $this->draftQuotation());
        $this->scenario('Cancelled then new quotation', fn () => $this->requotedOrder());
        $this->scenario('Invoice without quotation', fn () => $this->invoiceWithoutQuotation());
        $this->scenario('Direct purchase invoice', fn () => $this->directInvoice());
        $this->scenario('Cancelled invoice', fn () => $this->cancelledInvoice());
        $this->scenario('Direct invoice with sub-items', fn () => $this->directWeightInvoice());

        $this->command?->table(
            ['SKU', 'Item', 'On hand (pcs)', 'Weight (kg)', 'Incoming', 'Avg cost / pc', 'Value'],
            $this->stockReport()
        );
    }

    /**
     * رصيد كل صنف تجريبي كما تراه بطاقة الصنف: الرئيسي بكغمه وقيمته، والمقطع بعدده بلا قيمة.
     *
     * @return array<int, array<int, mixed>>
     */
    public function stockReport(): array
    {
        $ledger = app(StockLedgerService::class);
        $levels = app(StockLevelService::class);
        $warehouseId = ($this->warehouse ??= $this->warehouse())->id;

        return Product::query()
            ->where('sku', 'like', 'DEMO-%')
            ->where('item_type', 'goods')
            ->orderBy('sku')
            ->get()
            ->map(function (Product $product) use ($ledger, $levels, $warehouseId): array {
                $level = $levels->productLevels($product->id, $warehouseId)['levels'];
                $average = $ledger->averageCost($product->id);

                return [
                    $product->sku,
                    $product->name,
                    round((float) $level['on_hand'], 4),
                    round((float) $level['weight'], 4),
                    round((float) $level['incoming'], 4),
                    round($average, 4),
                    round((float) ProductCost::query()->where('product_id', $product->id)->value('value'), 4),
                ];
            })
            ->all();
    }

    // ------------------------------------------------------------ scenarios

    private function fullCycle(): void
    {
        $order = $this->order('Full cycle', [
            $this->line('DEMO-HINGE', 100),
            $this->weightLine('DEMO-1025', [['DEMO-1025-01', 10], ['DEMO-1025-02', 8]]),
        ]);

        $quotation = $this->approve($this->quote($order, ['DEMO-HINGE' => 4.5, 'DEMO-1025' => 12]));
        $this->receiveAll($order);
        $this->invoiceFromQuotation($quotation);
    }

    private function partialReceipt(): void
    {
        $order = $this->order('Partial receipt', [
            $this->line('DEMO-LOCK', 50),
            $this->weightLine('DEMO-4500', [['DEMO-4500-01', 20]]),
        ]);

        $this->approve($this->quote($order, ['DEMO-LOCK' => 7, 'DEMO-4500' => 15]));
        $this->receive($order, ['DEMO-LOCK' => 30, 'DEMO-4500-01' => 12]);
    }

    private function draftQuotation(): void
    {
        $order = $this->order('Draft quotation', [$this->line('DEMO-SCREW', 1000)]);

        $this->quote($order, ['DEMO-SCREW' => 0.05]);
    }

    private function requotedOrder(): void
    {
        $order = $this->order('Cancelled then new quotation', [$this->line('DEMO-HINGE', 40)]);

        app(PurchaseQuotationService::class)->cancel($this->quote($order, ['DEMO-HINGE' => 5]));
        $quotation = $this->approve($this->quote($order, ['DEMO-HINGE' => 4.8]));

        $this->receiveAll($order);
        $this->invoiceFromQuotation($quotation);
    }

    private function invoiceWithoutQuotation(): void
    {
        $order = $this->order('Invoice without quotation', [$this->line('DEMO-LOCK', 10)]);
        $this->receiveAll($order);

        // البضاعة دخلت بسعر تكلفة الصنف (6.5) والمورد فوتر بـ 7: فرق السعر 5.
        $service = app(PurchaseInvoiceService::class);
        $invoice = $service->createFromOrder($order, ['notes' => $order->notes]);
        $item = $invoice->items->first();

        $invoice = $service->update($invoice, [
            'purchase_order_id' => $order->id,
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currencyId,
            'notes' => $order->notes,
            'items' => [[
                'id' => $item->id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'product_id' => $item->product_id,
                'quantity' => (float) $item->quantity,
                'unit_price' => 7,
            ]],
        ]);

        $service->post($invoice);
    }

    private function directInvoice(): void
    {
        // صنف الوزن بلا طلبية يُفوتر بالكغم ويُستلم قطعا: 36 كغم ÷ (6 م × 1.2) = 5 قطع.
        $this->postDirectInvoice('Direct purchase invoice', [
            ['product_id' => $this->products['DEMO-SCREW']->id, 'quantity' => 500, 'unit_price' => 0.04],
            ['product_id' => $this->products['DEMO-1025-01']->id, 'quantity' => 36, 'unit_price' => 11.5],
        ]);
    }

    private function cancelledInvoice(): void
    {
        $invoice = $this->postDirectInvoice('Cancelled invoice', [
            ['product_id' => $this->products['DEMO-LOCK']->id, 'quantity' => 5, 'unit_price' => 7],
        ]);

        if ($invoice) {
            app(PurchaseInvoiceService::class)->cancel($invoice, 'Demo: cancelled invoice');
        }
    }

    private function directWeightInvoice(): void
    {
        // 4 قطع × 6.5 م × 1.5 = 39 كغم بسعر 14 للكغم.
        $line = $this->weightLine('DEMO-4500', [['DEMO-4500-01', 4]]);

        $this->postDirectInvoice('Direct invoice with sub-items', [array_merge($line, ['unit_price' => 14])]);
    }

    // ------------------------------------------------------------ building blocks

    private function scenario(string $name, callable $run): void
    {
        $exists = PurchaseOrder::query()->where('notes', self::NOTE_PREFIX.$name)->exists()
            || \Modules\Accounting\Entities\PurchaseInvoice::query()->where('notes', self::NOTE_PREFIX.$name)->exists();

        if ($exists) {
            $this->command?->line("skip  {$name} (already seeded)");

            return;
        }

        $run();
        $this->command?->info("done  {$name}");
    }

    private function order(string $scenario, array $items): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        return $service->confirm($service->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'notes' => self::NOTE_PREFIX.$scenario,
            'items' => $items,
        ]));
    }

    private function line(string $sku, float $quantity): array
    {
        return ['product_id' => $this->products[$sku]->id, 'quantity' => $quantity];
    }

    /**
     * سطر صنف وزن: الصنف الرئيسي، وتحته قطع اصنافه الفرعية بطولها ووزن مترها.
     *
     * @param  array<int, array{0:string, 1:float}>  $pieces  [رمز الصنف الفرعي، عدد القطع]
     */
    private function weightLine(string $mainSku, array $pieces): array
    {
        return [
            'product_id' => $this->products[$mainSku]->id,
            'quantity' => 1,
            // مبلغ سطر الوزن بالوزن الفعلي، وبدونه صفر؛ هنا بقدر الوزن المحسوب.
            'actual_weight' => round(array_sum(array_map(fn (array $piece): float => $piece[1] * (float) $this->products[$piece[0]]->length * (float) $this->products[$piece[0]]->weight_per_meter, $pieces)), 4),
            'pieces' => array_map(fn (array $piece) => [
                'product_id' => $this->products[$piece[0]]->id,
                'piece_count' => $piece[1],
                'piece_length' => (float) $this->products[$piece[0]]->length,
                'weight_per_meter' => (float) $this->products[$piece[0]]->weight_per_meter,
            ], $pieces),
        ];
    }

    /**
     * @param  array<string, float>  $prices  رمز الصنف ← السعر (لصنف الوزن: سعر الكغم)
     */
    private function quote(PurchaseOrder $order, array $prices): PurchaseQuotation
    {
        $bySku = collect($this->products)->mapWithKeys(fn (Product $product, string $sku) => [$product->id => $sku]);

        return app(PurchaseQuotationService::class)->createFromOrder($order, [
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currencyId,
            'items' => $order->items()->get()->map(fn ($item) => [
                'purchase_order_item_id' => $item->id,
                'unit_price' => $prices[$bySku[$item->product_id]] ?? 0,
            ])->all(),
        ]);
    }

    private function approve(PurchaseQuotation $quotation): PurchaseQuotation
    {
        return app(PurchaseQuotationService::class)->approve($quotation);
    }

    private function receiveAll(PurchaseOrder $order): void
    {
        $this->receive($order, null);
    }

    /**
     * @param  array<string, float>|null  $quantities  رمز الصنف (او الصنف الفرعي) ← الكمية؛ null = كل المتبقي
     */
    private function receive(PurchaseOrder $order, ?array $quantities): void
    {
        $service = app(GoodsReceiptService::class);
        $skuById = collect($this->products)->mapWithKeys(fn (Product $product, string $sku) => [$product->id => $sku]);

        $items = collect($service->outstandingLines($order->refresh()))
            ->map(function (array $line) use ($quantities, $skuById) {
                $quantity = $quantities === null ? $line['outstanding'] : ($quantities[$skuById[$line['product_id']] ?? ''] ?? 0);

                return [
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'purchase_order_item_piece_id' => $line['purchase_order_item_piece_id'],
                    'quantity' => $quantity,
                ];
            })
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values()
            ->all();

        $service->post($service->create([
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => $items,
        ]));
    }

    private function invoiceFromQuotation(PurchaseQuotation $quotation): void
    {
        $service = app(PurchaseInvoiceService::class);

        $service->post($service->createFromQuotation($quotation->refresh()));
    }

    private function postDirectInvoice(string $scenario, array $items)
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currencyId,
            'notes' => self::NOTE_PREFIX.$scenario,
            'items' => $items,
        ]));
    }

    // ------------------------------------------------------------ master data

    private function warehouse(): Warehouse
    {
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('id')->first();

        if ($warehouse) {
            return $warehouse;
        }

        $warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $warehouse->save();

        return $warehouse;
    }

    private function supplier(): User
    {
        $supplier = User::query()->where('name', 'مورد تجريبي - شركة الألمنيوم')->first()
            ?? User::create(['name' => 'مورد تجريبي - شركة الألمنيوم', 'phone' => '0590000000', 'password' => bcrypt(str()->random(40))]);

        $supplier->assignRole(Role::firstOrCreate(['name' => 'Supplier', 'guard_name' => 'web']));
        app(AccountService::class)->createForUser($supplier, 'supplier_account_parent');

        return $supplier;
    }

    /**
     * اصناف بالكمية، واصناف وزن رئيسية باصنافها الفرعية (طول القطعة ووزن المتر)، وخدمة.
     */
    private function products(): void
    {
        $quantityItems = [
            'DEMO-HINGE' => ['مفصلة ألمنيوم 3 إنش', 4.5],
            'DEMO-LOCK' => ['قفل سحاب للأبواب', 6.5],
            'DEMO-SCREW' => ['براغي تثبيت 4 مم', 0.04],
        ];

        foreach ($quantityItems as $sku => [$name, $cost]) {
            $this->products[$sku] = $this->product($sku, $name, ['pricing_type' => 'simple', 'cost_price' => $cost, 'sale_price' => round($cost * 1.4, 2)]);
        }

        $weightItems = [
            'DEMO-1025' => ['بدون دهان 1025', 12, [
                'DEMO-1025-01' => ['ثابت 3 مجاري 1025', 6, 1.2],
                'DEMO-1025-02' => ['متحرك 1025', 6, 0.9],
            ]],
            'DEMO-4500' => ['مدهون أبيض 4500', 15, [
                'DEMO-4500-01' => ['حلق 4500', 6.5, 1.5],
            ]],
        ];

        foreach ($weightItems as $mainSku => [$name, $kgCost, $subItems]) {
            $main = $this->products[$mainSku] = $this->product($mainSku, $name, ['pricing_type' => 'weight', 'cost_price' => $kgCost, 'sale_price' => $kgCost + 4]);

            foreach ($subItems as $sku => [$subName, $length, $weightPerMeter]) {
                $this->products[$sku] = $this->product($sku, $subName, [
                    'pricing_type' => 'weight',
                    'main_product_id' => $main->id,
                    'length' => $length,
                    'weight_per_meter' => $weightPerMeter,
                ]);
            }
        }

        $this->products['DEMO-TRANSPORT'] = $this->product('DEMO-TRANSPORT', 'أجور نقل', ['item_type' => 'service', 'pricing_type' => 'simple', 'cost_price' => 150]);
    }

    private function product(string $sku, string $name, array $attributes): Product
    {
        $product = Product::query()->where('sku', $sku)->first() ?? new Product([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'barcode' => $sku,
        ]);

        $product->fill(array_merge(['item_type' => 'goods', 'base_price' => 0, 'status' => 1], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product->refresh();
    }
}
