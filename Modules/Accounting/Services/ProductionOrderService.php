<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\LaborRateBasis;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Support\ProductionCost;
use Modules\Core\Entities\User;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServicePricingBasis;
use Modules\Items\Support\ProductSearch;

/**
 * قسم الإنتاج: أمر إنتاج ينفذ طلبية بيع، ببنود تكلفة من ثلاثة انواع (اصناف، أجور،
 * خدمات داخلية او خارجية). مجاميع التكلفة وتكلفة الوحدة وقيمة البيع تُحسب عند كل
 * حفظ (ProductionCost)، فلا يُدخلها المستخدم يدوياً.
 *
 * المراحل: مسودة ← قيد الإنتاج (وتنتقل الطلبية الى "قيد التحضير") ← مكتمل (تُثبَّت
 * التكلفة، ويمكن اعادة فتحه للتصحيح) او ملغى.
 */
class ProductionOrderService
{
    private const RELATIONS = ['salesOrder', 'client', 'branch', 'warehouse', 'currency', 'materials.product', 'labor.worker', 'services.product', 'services.serviceProvider'];

    public function create(array $data): ProductionOrder
    {
        return DB::transaction(function () use ($data) {
            $salesOrder = $this->salesOrder($data['sales_order_id'] ?? null);
            $data = $this->normalizeData($data, $salesOrder);
            $lines = $this->normalizeLines($data);
            unset($data['materials'], $data['labor'], $data['services']);

            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'production_order',
                $data['order_date'],
                ['prefix' => 'PROD']
            );
            $data['status'] = ProductionOrderStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $order = ProductionOrder::create(array_merge($data, $this->totals($lines, $data)));
            $this->syncLines($order, $lines);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * أمر إنتاج من طلبية بيع، ببنوده المقترحة منها (الاصناف وخدماتها).
     */
    public function createFromSalesOrder(SalesOrder $salesOrder, array $data = []): ProductionOrder
    {
        return $this->create(array_replace([
            'sales_order_id' => $salesOrder->id,
            'order_date' => now()->toDateString(),
            'due_date' => $salesOrder->expected_delivery_date?->toDateString(),
            'warehouse_id' => $salesOrder->warehouse_id,
        ], $this->linesFromSalesOrder($salesOrder), $data));
    }

    public function update(ProductionOrder $order, array $data): ProductionOrder
    {
        if (! $order->canBeEdited()) {
            throw new DomainException(__('Only open production orders can be updated.'));
        }

        return DB::transaction(function () use ($order, $data) {
            $salesOrder = $this->salesOrder($data['sales_order_id'] ?? null);
            $data = $this->normalizeData($data, $salesOrder);
            $lines = $this->normalizeLines($data);

            unset($data['materials'], $data['labor'], $data['services']);

            $order->update(array_merge($data, $this->totals($lines, $data)));
            $this->syncLines($order, $lines);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(ProductionOrder $order): void
    {
        if (! $order->canBeDeleted()) {
            throw new DomainException(__('Only draft production orders can be deleted.'));
        }

        DB::transaction(function () use ($order) {
            $order->materials()->delete();
            $order->labor()->delete();
            $order->services()->delete();
            $order->delete();
        });
    }

    /**
     * بدء الإنتاج، وتنتقل الطلبية المصدر الى "قيد التحضير" ان كانت مسودة او مؤكدة.
     */
    public function start(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->locked($order);

            if (! $order->canStart()) {
                throw new DomainException(__('Only draft production orders can be started.'));
            }

            $order->update(['status' => ProductionOrderStatus::IN_PROGRESS->value(), 'started_at' => now()]);

            if ($order->salesOrder && in_array($order->salesOrder->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CONFIRMED], true)) {
                try {
                    app(SalesOrderService::class)->sendToPreparation($order->salesOrder);
                } catch (DomainException) {
                    // الطلبية بلا بنود لا تنتقل؛ الإنتاج يبدأ مع ذلك.
                }
            }

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * انهاء الإنتاج وتثبيت التكلفة، ويُشترط وجود تكلفة وكمية منتجة.
     */
    public function complete(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->locked($order);

            if (! $order->canComplete()) {
                throw new DomainException(__('Only open production orders can be completed.'));
            }

            if ((float) $order->total_cost <= 0) {
                throw new DomainException(__('Production order must have at least one cost line before completion.'));
            }

            if ((float) $order->produced_quantity <= 0) {
                throw new DomainException(__('Produced quantity must be greater than zero.'));
            }

            $order->update([
                'status' => ProductionOrderStatus::COMPLETED->value(),
                'started_at' => $order->started_at ?? now(),
                'completed_at' => now(),
            ]);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    public function reopen(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->locked($order);

            if (! $order->canBeReopened()) {
                throw new DomainException(__('Only completed production orders can be reopened.'));
            }

            $order->update(['status' => ProductionOrderStatus::IN_PROGRESS->value(), 'completed_at' => null]);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    public function cancel(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->locked($order);

            if (! $order->canBeCancelled()) {
                throw new DomainException(__('Final production orders cannot be changed.'));
            }

            $order->update(['status' => ProductionOrderStatus::CANCELLED->value()]);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * البنود المقترحة من طلبية البيع: اصنافها مواداً بتكلفة بطاقة الصنف (والموزون
     * بوزنه الفعلي ان أُدخل والا الكلي)، وخدماتها بمصدر تكلفتها وجهتها وتكلفتها.
     *
     * @return array{materials: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>}
     */
    public function linesFromSalesOrder(SalesOrder $salesOrder): array
    {
        $salesOrder->loadMissing(['items.product.translations']);

        $materials = [];
        $services = [];

        foreach ($salesOrder->items as $item) {
            $product = $item->product;

            if ($item->isService()) {
                $services[] = [
                    'product_id' => $item->product_id,
                    'description' => $item->description ?: $product?->name,
                    'cost_source' => $item->cost_source ?: ServiceCostSource::INTERNAL->value,
                    'service_provider_id' => $item->service_provider_id,
                    'pricing_basis' => $item->pricing_basis ?: ServicePricingBasis::QUANTITY->value,
                    'quantity' => (float) $item->quantity,
                    'unit_cost' => (float) $item->unit_cost ?: $this->averageCost($product?->id),
                ];

                continue;
            }

            $isWeight = $product?->pricing_type === 'weight' || (float) $item->total_weight > 0;
            $weight = (float) $item->actual_weight > 0 ? (float) $item->actual_weight : (float) $item->total_weight;

            $materials[] = [
                'sales_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'description' => $item->description ?: $product?->name,
                'pricing_type' => $isWeight ? 'weight' : 'simple',
                'quantity' => (float) $item->quantity,
                'weight' => $isWeight ? $weight : 0,
                'unit_cost' => $this->averageCost($product?->id),
            ];
        }

        return ['materials' => $materials, 'services' => $services];
    }

    /**
     * ملخص التكلفة من بنود النموذج قبل الحفظ، بنفس حساب الحفظ (ProductionCost)، ومعه
     * تكلفة كل بند. الأجر المقطوع كميته 1 دائماً.
     *
     * @param  array<string, mixed>  $data  materials, labor, services, produced_quantity, sales_order_id
     * @return array{summary: array<string, float|null>, materials_weight: float, lines: array{materials: list<float>, labor: list<float>, services: list<float>}}
     */
    public function costPreview(array $data): array
    {
        $materials = array_values(array_filter((array) ($data['materials'] ?? []), 'is_array'));
        $labor = array_map(
            fn (array $line): array => ($line['rate_basis'] ?? null) === LaborRateBasis::FIXED->value ? array_replace($line, ['quantity' => 1]) : $line,
            array_values(array_filter((array) ($data['labor'] ?? []), 'is_array')),
        );
        $services = array_values(array_filter((array) ($data['services'] ?? []), 'is_array'));
        $salesOrder = filled($data['sales_order_id'] ?? null) ? SalesOrder::query()->find($data['sales_order_id']) : null;

        return [
            'summary' => ProductionCost::summary($materials, $labor, $services, $data['produced_quantity'] ?? 1, $this->salesAmount($salesOrder)),
            'materials_weight' => self::materialsWeight($materials),
            'lines' => [
                'materials' => array_map(fn (array $line): float => ProductionCost::materialTotal($line), $materials),
                'labor' => array_map(fn (array $line): float => ProductionCost::laborTotal($line), $labor),
                'services' => array_map(fn (array $line): float => ProductionCost::serviceTotal($line), $services),
            ],
        ];
    }

    /**
     * طلبيات البيع غير الملغاة التي يُنتج لها: الاحدث اولاً، بالمرجع او اسم الزبون.
     *
     * @return list<array{value: int, label: string}>
     */
    public function salesOrderOptions(?string $search = null): array
    {
        $search = trim((string) $search);

        return SalesOrder::query()
            ->with('client')
            ->where('status', '!=', SalesOrderStatus::CANCELLED->value())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('reference_no', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->latest('order_date')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SalesOrder $order): array => ['value' => (int) $order->id, 'label' => self::salesOrderLabel($order)])
            ->all();
    }

    public static function salesOrderLabel(SalesOrder $order): string
    {
        return trim($order->reference_no.($order->client ? ' - '.$order->client->name : ''));
    }

    /**
     * ما يأخذه الأمر من طلبية البيع المختارة: الزبون، العملة (تتبعها)، المستودع وتاريخ
     * التسليم المقترحان، وبنودها المقترحة.
     *
     * @return array<string, mixed>|null
     */
    public function salesOrderDefaults(mixed $salesOrderId): ?array
    {
        $salesOrder = filled($salesOrderId)
            ? SalesOrder::query()->with('client')->whereKey($salesOrderId)->where('status', '!=', SalesOrderStatus::CANCELLED->value())->first()
            : null;

        if (! $salesOrder) {
            return null;
        }

        return [
            'sales_order' => ['value' => (int) $salesOrder->id, 'label' => self::salesOrderLabel($salesOrder)],
            'client_label' => $salesOrder->client?->name,
            'currency_id' => $salesOrder->currency_id,
            'warehouse_id' => $salesOrder->warehouse_id,
            'due_date' => $salesOrder->expected_delivery_date?->toDateString(),
            'sales_amount' => $this->salesAmount($salesOrder),
            ...$this->linesFromSalesOrder($salesOrder),
        ];
    }

    /**
     * ما يقترحه اختيار صنف في بند الاصناف: وصفه، نوع تسعيره، وتكلفة بطاقته.
     *
     * @return array{description: string|null, pricing_type: string, unit_cost: string}|null
     */
    public function materialDefaults(mixed $productId): ?array
    {
        $product = filled($productId) ? Product::query()->with('translations')->find($productId) : null;

        if (! $product) {
            return null;
        }

        return [
            'description' => $product->name,
            'pricing_type' => $product->pricing_type === 'weight' ? 'weight' : 'simple',
            'unit_cost' => number_format($this->averageCost($product->id), 4, '.', ''),
        ];
    }

    /**
     * ما يقترحه اختيار خدمة: وصفها، تكلفتها، مصدر تكلفتها، واساس تسعيرها. الخدمة الموزونة
     * (كدهان الالمنيوم) تقترح كمية بوزن الاصناف الموزونة في الأمر.
     *
     * @param  array<int|string, array<string, mixed>>  $materials
     * @return array<string, string>|null
     */
    public function serviceDefaults(mixed $productId, array $materials = []): ?array
    {
        $product = filled($productId) ? Product::query()->with('translations')->find($productId) : null;

        if (! $product) {
            return null;
        }

        $defaults = [
            'description' => (string) $product->name,
            'unit_cost' => number_format($this->averageCost($product->id), 4, '.', ''),
            'cost_source' => ServiceCostSource::tryFrom((string) $product->service_cost_source)?->value ?? ServiceCostSource::INTERNAL->value,
            'pricing_basis' => $product->pricing_type === 'weight' ? ServicePricingBasis::WEIGHT->value : ServicePricingBasis::QUANTITY->value,
        ];

        if ($product->pricing_type === 'weight' && ($weight = self::materialsWeight($materials)) > 0) {
            $defaults['quantity'] = number_format($weight, 4, '.', '');
        }

        return $defaults;
    }

    /**
     * وزن الاصناف الموزونة في الأمر، يقترح كمية خدمة بالكيلوغرام (كدهان الالمنيوم).
     *
     * @param  iterable<int|string, array<string, mixed>>  $materials
     */
    public static function materialsWeight(iterable $materials): float
    {
        return round(collect($materials)
            ->filter(fn ($line): bool => is_array($line) && ($line['pricing_type'] ?? 'simple') === 'weight')
            ->sum(fn (array $line): float => ProductionCost::number($line['weight'] ?? 0)), 4);
    }

    /**
     * الجهات التي تنفذ خدمة خارجية: الموردون وشركات التوصيل والشحن.
     *
     * @return list<array{value: int, label: string}>
     */
    public function providerOptions(?string $search = null): array
    {
        $search = trim((string) $search);

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['Supplier', 'Delivery Company', 'Shipping Company']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => (string) $user->name])
            ->all();
    }

    /**
     * العمال: كل المستخدمين بالاسم او الهاتف.
     *
     * @return list<array{value: int, label: string}>
     */
    public function workerOptions(?string $search = null): array
    {
        $search = trim((string) $search);

        return User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => (string) $user->name])
            ->all();
    }

    /**
     * قيمة البيع للمقارنة بالتكلفة: صافي الطلبية بعد الخصومات وقبل الضريبة.
     */
    public function salesAmount(?SalesOrder $salesOrder): float
    {
        return $salesOrder ? round((float) $salesOrder->total_amount - (float) $salesOrder->tax_amount, 4) : 0.0;
    }

    private function salesOrder(mixed $id): ?SalesOrder
    {
        if (blank($id)) {
            return null;
        }

        $salesOrder = SalesOrder::query()->find($id);

        if (! $salesOrder) {
            throw new DomainException(__('Sales order not found.'));
        }

        if ($salesOrder->status === SalesOrderStatus::CANCELLED) {
            throw new DomainException(__('Cancelled sales orders cannot be produced.'));
        }

        return $salesOrder;
    }

    private function locked(ProductionOrder $order): ProductionOrder
    {
        return ProductionOrder::query()->with('salesOrder')->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * العميل والعملة يتبعان طلبية البيع ان وُجدت، والا العملة الاساسية.
     */
    private function normalizeData(array $data, ?SalesOrder $salesOrder): array
    {
        $producedQuantity = ProductionCost::number($data['produced_quantity'] ?? 1);

        if ($producedQuantity <= 0) {
            throw new DomainException(__('Produced quantity must be greater than zero.'));
        }

        $currencyId = $salesOrder?->currency_id
            ?? (filled($data['currency_id'] ?? null) ? (int) $data['currency_id'] : app(CurrencyExchangeRateService::class)->baseCurrencyId());

        if (! $currencyId) {
            throw new DomainException(__('Currency is required.'));
        }

        return [
            'sales_order_id' => $salesOrder?->id,
            'client_id' => $salesOrder?->client_id ?? (filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null),
            'branch_id' => $salesOrder?->branch_id ?? (filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null),
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : null,
            'currency_id' => (int) $currencyId,
            'order_date' => $data['order_date'] ?? now()->toDateString(),
            'due_date' => filled($data['due_date'] ?? null) ? $data['due_date'] : null,
            'produced_quantity' => round($producedQuantity, 4),
            'sales_amount' => $this->salesAmount($salesOrder),
            'notes' => $data['notes'] ?? null,
            'materials' => $data['materials'] ?? [],
            'labor' => $data['labor'] ?? [],
            'services' => $data['services'] ?? [],
        ];
    }

    /**
     * @return array{materials: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>}
     */
    private function normalizeLines(array $data): array
    {
        return [
            'materials' => $this->normalizeMaterials($data['materials']),
            'labor' => $this->normalizeLabor($data['labor']),
            'services' => $this->normalizeServices($data['services']),
        ];
    }

    private function normalizeMaterials(array $lines): array
    {
        $lines = collect($lines)->filter(fn ($line): bool => is_array($line) && (filled($line['product_id'] ?? null) || filled($line['description'] ?? null)))->values();
        $products = Product::query()->with('translations')->whereIn('id', $lines->pluck('product_id')->filter()->all())->get()->keyBy('id');

        return $lines->map(function (array $line, int $index) use ($products): array {
            $product = filled($line['product_id'] ?? null) ? $products->get((int) $line['product_id']) : null;

            if (filled($line['product_id'] ?? null) && ! $product) {
                throw new DomainException(__('Item not found.'));
            }

            if ($product?->isService()) {
                throw new DomainException(__('":item" is a service; add it in the services list.', ['item' => ProductSearch::label($product->id)]));
            }

            $pricingType = $product ? ($product->pricing_type === 'weight' ? 'weight' : 'simple') : (($line['pricing_type'] ?? 'simple') === 'weight' ? 'weight' : 'simple');
            $row = [
                'sales_order_item_id' => filled($line['sales_order_item_id'] ?? null) ? (int) $line['sales_order_item_id'] : null,
                'product_id' => $product?->id,
                'description' => filled($line['description'] ?? null) ? $line['description'] : $product?->name,
                'pricing_type' => $pricingType,
                'quantity' => $this->nonNegative($line['quantity'] ?? 0),
                'weight' => $pricingType === 'weight' ? $this->nonNegative($line['weight'] ?? 0) : 0,
                'unit_cost' => $this->nonNegative($line['unit_cost'] ?? null, $this->averageCost($product?->id)),
                'notes' => $line['notes'] ?? null,
                'sort_order' => $index,
            ];

            return array_merge($row, ['total_cost' => ProductionCost::materialTotal($row)]);
        })->all();
    }

    private function normalizeLabor(array $lines): array
    {
        return collect($lines)
            ->filter(fn ($line): bool => is_array($line) && (filled($line['worker_id'] ?? null) || filled($line['worker_name'] ?? null) || filled($line['description'] ?? null)))
            ->values()
            ->map(function (array $line, int $index): array {
                $basis = LaborRateBasis::tryFrom((string) ($line['rate_basis'] ?? ''))?->value ?? LaborRateBasis::HOUR->value;
                $row = [
                    'worker_id' => filled($line['worker_id'] ?? null) ? (int) $line['worker_id'] : null,
                    'worker_name' => $line['worker_name'] ?? null,
                    'description' => $line['description'] ?? null,
                    'rate_basis' => $basis,
                    'quantity' => $basis === LaborRateBasis::FIXED->value ? 1.0 : $this->nonNegative($line['quantity'] ?? 0),
                    'rate' => $this->nonNegative($line['rate'] ?? 0),
                    'work_date' => filled($line['work_date'] ?? null) ? $line['work_date'] : null,
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $index,
                ];

                return array_merge($row, ['total_cost' => ProductionCost::laborTotal($row)]);
            })
            ->all();
    }

    private function normalizeServices(array $lines): array
    {
        $lines = collect($lines)->filter(fn ($line): bool => is_array($line) && (filled($line['product_id'] ?? null) || filled($line['description'] ?? null)))->values();
        $products = Product::query()->with('translations')->whereIn('id', $lines->pluck('product_id')->filter()->all())->get()->keyBy('id');

        return $lines->map(function (array $line, int $index) use ($products): array {
            $product = filled($line['product_id'] ?? null) ? $products->get((int) $line['product_id']) : null;

            if (filled($line['product_id'] ?? null) && ! $product?->isService()) {
                throw new DomainException(__('":item" is not a service; add it in the items list.', ['item' => ProductSearch::label($line['product_id'])]));
            }

            $source = ServiceCostSource::tryFrom((string) ($line['cost_source'] ?? ''))?->value
                ?? ServiceCostSource::tryFrom((string) $product?->service_cost_source)?->value
                ?? ServiceCostSource::INTERNAL->value;
            $isExternal = $source === ServiceCostSource::EXTERNAL->value;

            $row = [
                'product_id' => $product?->id,
                'description' => filled($line['description'] ?? null) ? $line['description'] : $product?->name,
                'cost_source' => $source,
                'service_provider_id' => $isExternal && filled($line['service_provider_id'] ?? null) ? (int) $line['service_provider_id'] : null,
                'provider_reference' => $isExternal ? ($line['provider_reference'] ?? null) : null,
                'pricing_basis' => ServicePricingBasis::tryFrom((string) ($line['pricing_basis'] ?? ''))?->value ?? ServicePricingBasis::QUANTITY->value,
                'quantity' => $this->nonNegative($line['quantity'] ?? 0),
                'unit_cost' => $this->nonNegative($line['unit_cost'] ?? null, $this->averageCost($product?->id)),
                'sent_date' => $isExternal && filled($line['sent_date'] ?? null) ? $line['sent_date'] : null,
                'received_date' => $isExternal && filled($line['received_date'] ?? null) ? $line['received_date'] : null,
                'notes' => $line['notes'] ?? null,
                'sort_order' => $index,
            ];

            return array_merge($row, ['total_cost' => ProductionCost::serviceTotal($row)]);
        })->all();
    }

    private function totals(array $lines, array $data): array
    {
        $summary = ProductionCost::summary($lines['materials'], $lines['labor'], $lines['services'], $data['produced_quantity'], $data['sales_amount']);

        return array_intersect_key($summary, array_flip(['materials_cost', 'labor_cost', 'services_cost', 'total_cost', 'unit_cost', 'sales_amount']));
    }

    /**
     * البنود لا يشير اليها شيء، فتُستبدل كلها عند كل حفظ.
     */
    private function syncLines(ProductionOrder $order, array $lines): void
    {
        $userId = auth()->id();

        $order->materials()->delete();
        $order->labor()->delete();
        $order->services()->delete();

        foreach ($lines['materials'] as $line) {
            $order->materials()->create(array_merge($line, ['created_by' => $userId]));
        }

        foreach ($lines['labor'] as $line) {
            $order->labor()->create(array_merge($line, ['created_by' => $userId]));
        }

        foreach ($lines['services'] as $line) {
            $order->services()->create(array_merge($line, ['created_by' => $userId]));
        }
    }

    /**
     * تكلفة المادة المقترحة: المتوسط المرجّح من المشتريات الفعلية (مبدأ التكلفة التاريخية)، لا رقم
     * يُكتب في بطاقة الصنف. ما لم يُشترَ بعد تكلفته صفر حتى تُكتب في السطر.
     */
    private function averageCost(?int $productId): float
    {
        return $productId ? app(StockLedgerService::class)->averageCost($productId) : 0.0;
    }

    private function nonNegative(mixed $value, float $default = 0): float
    {
        $number = ($value === null || $value === '') ? $default : $value;

        if (is_string($number)) {
            $number = str_replace([',', ' '], '', $number);
        }

        if (! is_numeric($number)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        if ((float) $number < 0) {
            throw new DomainException(__('Cost values cannot be negative.'));
        }

        return round((float) $number, 4);
    }
}
