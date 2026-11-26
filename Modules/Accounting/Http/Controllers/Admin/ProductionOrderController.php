<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Accounting\Entities\ProductionOrderLabor;
use Modules\Accounting\Entities\ProductionOrderMaterial;
use Modules\Accounting\Entities\ProductionOrderServiceLine;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\LaborRateBasis;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Requests\Admin\ProductionOrderRequest;
use Modules\Accounting\Repositories\Contracts\ProductionOrderRepositoryInterface;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\ProductionOrderService;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\QuickCreate;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\UserCellData;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServicePricingBasis;
use Modules\Items\Support\ProductSearch;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * أوامر الإنتاج: أمر ينفذ طلبية بيع، ببنود تكلفة من ثلاثة انواع (اصناف، أجور، خدمات)، ومراحله
 * مسودة ← قيد الإنتاج ← مكتمل (يُعاد فتحه) او ملغى. الحساب والقواعد كلها في
 * ProductionOrderService و ProductionCost؛ الصفحة تعرض معاينة التكلفة من نقطة cost-preview.
 */
class ProductionOrderController extends Controller
{
    use DeletesManyDocuments;

    public function __construct(
        private readonly ProductionOrderRepositoryInterface $orders,
        private readonly ProductionOrderService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/ProductionOrder/Index', [
            'table' => AdminTable::for($this->orders->tableQuery())
                ->search(['reference_no'], null)
                ->filters(
                    AdminFilter::exact('status'),
                    AdminFilter::text('reference_no'),
                    $this->relationText('sales_order', 'salesOrder', 'reference_no'),
                    $this->relationText('client', 'client', 'name'),
                    AdminFilter::exact('currency', 'currency_id'),
                    ...AdminFilter::dateRange('order_date'),
                    ...AdminFilter::dateRange('due_date'),
                    ...AdminFilter::numberRange('total_cost'),
                )
                ->sorts('reference_no', 'order_date', 'due_date', 'total_cost', 'unit_cost', 'status', 'id')
                ->defaultSort('-order_date')
                ->withCreator()
                ->rows(fn (ProductionOrder $order) => [
                    'id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'order_date' => $order->order_date?->format('d/m/Y'),
                    'due_date' => $order->due_date?->format('d/m/Y'),
                    'sales_order' => $order->salesOrder ? ['id' => $order->salesOrder->id, 'reference_no' => $order->salesOrder->reference_no] : null,
                    'client' => UserCellData::of($order->client),
                    'produced_quantity' => Number::quantity($order->produced_quantity),
                    'total_cost' => number_format((float) $order->total_cost, 2),
                    'unit_cost' => number_format((float) $order->unit_cost, 2),
                    'sales_amount' => number_format((float) $order->sales_amount, 2),
                    'margin' => number_format($order->margin(), 2),
                    'margin_negative' => $order->margin() < 0,
                    'currency' => $order->currency?->code,
                    'status' => StatusBadge::of($order->status),
                    'notes' => $order->notes,
                    'editable' => $order->canBeEdited(),
                    'can_delete' => $order->canBeDeleted(),
                ]),
            'statusOptions' => SelectOptions::from(ProductionOrderStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
        ]);
    }

    /**
     * "إنشاء أمر إنتاج" من شاشة طلبية البيع يفتح الصفحة والطلبية مختارة وبنودها مقترحة.
     */
    public function add(Request $request, CurrencyExchangeRateService $rates): Response
    {
        $order = [
            'sales_order_id' => null,
            'order_date' => now()->toDateString(),
            'due_date' => null,
            'warehouse_id' => null,
            'currency_id' => $rates->baseCurrencyId(),
            'produced_quantity' => '1',
            'notes' => null,
            'materials' => [],
            'labor' => [],
            'services' => [],
        ];
        $context = ['sales_order' => null, 'client_label' => null, 'sales_amount' => 0];

        if ($defaults = $this->service->salesOrderDefaults($request->query('sales_order_id'))) {
            $order = array_replace($order, [
                'sales_order_id' => $defaults['sales_order']['value'],
                'currency_id' => $defaults['currency_id'],
                'warehouse_id' => $defaults['warehouse_id'],
                'due_date' => $defaults['due_date'],
                'materials' => $this->formLines($defaults['materials']),
                'services' => $this->formLines($defaults['services']),
            ]);
            $context = ['sales_order' => $defaults['sales_order'], 'client_label' => $defaults['client_label'], 'sales_amount' => $defaults['sales_amount']];
        }

        return Inertia::render('Accounting/ProductionOrder/Add', [
            'order' => $order,
            'context' => $context,
            'referenceNo' => app(ReferenceNumberService::class)->preview('production_order', now(), ['prefix' => 'PROD']),
            'statusLabel' => ProductionOrderStatus::DRAFT->getLabel(),
            ...$this->formOptions(),
        ]);
    }

    public function store(ProductionOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->service->create($request->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.production-orders.edit', $order->id)->with('success', __('Production order created successfully'));
    }

    /**
     * صفحة الأمر: نموذج تعديل ما دام مفتوحاً (مسودة او قيد الإنتاج)، ثم عرض للقراءة.
     */
    public function edit(int $productionOrder): Response
    {
        $order = $this->orders->findForPage($productionOrder);

        return Inertia::render('Accounting/ProductionOrder/Edit', [
            'order' => $this->formState($order),
            'context' => [
                'sales_order' => $order->salesOrder ? ['value' => $order->salesOrder->id, 'label' => ProductionOrderService::salesOrderLabel($order->salesOrder)] : null,
                'client_label' => $order->client?->name,
                'sales_amount' => (float) $order->sales_amount,
            ],
            'record' => [
                'id' => $order->id,
                'reference_no' => $order->reference_no,
                'status' => StatusBadge::of($order->status),
                'editable' => $order->canBeEdited(),
                'can_start' => $order->canStart(),
                'can_complete' => $order->canComplete(),
                'can_reopen' => $order->canBeReopened(),
                'can_cancel' => $order->canBeCancelled(),
                'can_delete' => $order->canBeDeleted(),
                'sales_order' => $order->salesOrder ? ['id' => $order->salesOrder->id, 'reference_no' => $order->salesOrder->reference_no] : null,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(ProductionOrderRequest $request, int $productionOrder): RedirectResponse
    {
        return $this->attempt(fn (ProductionOrder $order) => $this->service->update($order, $request->validated()), $productionOrder, __('Production order updated successfully'));
    }

    /**
     * يحفظ التعديلات (ان كان الأمر مفتوحاً) ثم يبدأ الإنتاج، كما كان زر «بدء الإنتاج».
     */
    public function start(int $productionOrder): RedirectResponse
    {
        return $this->transition($productionOrder, 'start', __('Production started successfully'));
    }

    /**
     * يحفظ التعديلات (ان كان الأمر مفتوحاً) ثم ينهي الإنتاج ويثبّت التكلفة.
     */
    public function complete(int $productionOrder): RedirectResponse
    {
        return $this->transition($productionOrder, 'complete', __('Production order completed successfully'));
    }

    public function reopen(int $productionOrder): RedirectResponse
    {
        return $this->attempt(fn (ProductionOrder $order) => $this->service->reopen($order), $productionOrder, __('Production order reopened successfully'));
    }

    public function cancel(int $productionOrder): RedirectResponse
    {
        return $this->attempt(fn (ProductionOrder $order) => $this->service->cancel($order), $productionOrder, __('Production order cancelled successfully'));
    }

    public function destroy(int $productionOrder): RedirectResponse
    {
        try {
            $this->service->delete($this->find($productionOrder));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.production-orders.index')->with('success', __('Production order deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->orders->tableQuery();
    }

    /**
     * @param  ProductionOrder  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function info(int $productionOrder): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($productionOrder)));
    }

    /**
     * ملخص التكلفة وتكلفة كل بند من النموذج قبل الحفظ، بنفس حساب الحفظ.
     */
    public function costPreview(Request $request): JsonResponse
    {
        $preview = $this->service->costPreview($request->only(['materials', 'labor', 'services', 'produced_quantity', 'sales_order_id']));

        return response()->json($preview);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        return response()->json($this->service->salesOrderOptions($request->string('q')->toString()));
    }

    /**
     * ما يأخذه الأمر من طلبية البيع المختارة، وبنودها المقترحة.
     */
    public function salesOrder(Request $request): JsonResponse
    {
        $defaults = $this->service->salesOrderDefaults($request->query('sales_order_id'));

        if ($defaults) {
            $defaults['materials'] = $this->formLines($defaults['materials']);
            $defaults['services'] = $this->formLines($defaults['services']);
        }

        return response()->json($defaults);
    }

    /**
     * الاصناف (بضاعة فقط) لبنود الاصناف.
     */
    public function materials(Request $request): JsonResponse
    {
        // كاصناف طلبيات البيع والشراء: المقطع بألوانه، مع مجموعة اللون.
        return response()->json(collect(ItemsGrid::searchItems($request->string('q')->toString()))
            ->map(fn (array $item): array => [
                'value' => $item['id'],
                'label' => $item['label'].(filled($item['group_label']) ? ' · '.$item['group_label'] : ''),
            ])
            ->all());
    }

    public function services(Request $request): JsonResponse
    {
        return response()->json($this->options(ProductSearch::serviceOptions($request->string('q')->toString(), 50)));
    }

    public function material(Request $request): JsonResponse
    {
        return response()->json($this->service->materialDefaults($request->query('product_id')));
    }

    public function service(Request $request): JsonResponse
    {
        return response()->json($this->service->serviceDefaults($request->input('product_id'), (array) $request->input('materials', [])));
    }

    public function workers(Request $request): JsonResponse
    {
        return response()->json($this->service->workerOptions($request->string('q')->toString()));
    }

    public function providers(Request $request): JsonResponse
    {
        return response()->json($this->service->providerOptions($request->string('q')->toString()));
    }

    /**
     * جهة خدمة جديدة (مورّد) تُنشأ من النموذج نفسه بدل الخروج منه.
     */
    public function storeProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => __('Name'), 'email' => __('Email'), 'phone' => __('Phone')]);

        $id = QuickCreate::party($data, 'Supplier');

        return response()->json(['value' => $id, 'label' => $data['name']]);
    }

    private function transition(int $id, string $method, string $message): RedirectResponse
    {
        $order = $this->find($id);

        try {
            if ($order->canBeEdited() && request()->has('order_date')) {
                $order = $this->service->update($order, app(ProductionOrderRequest::class)->validated());
            }

            $this->service->{$method}($order);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * @param  callable(ProductionOrder): mixed  $action
     */
    private function attempt(callable $action, int $id, string $message): RedirectResponse
    {
        try {
            $action($this->find($id));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    private function find(int $id): ProductionOrder
    {
        /** @var ProductionOrder */
        return $this->orders->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formState(ProductionOrder $order): array
    {
        return [
            'sales_order_id' => $order->sales_order_id,
            'order_date' => $order->order_date?->toDateString(),
            'due_date' => $order->due_date?->toDateString(),
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $order->currency_id,
            'produced_quantity' => Number::clean($order->produced_quantity),
            'notes' => $order->notes,
            'materials' => $order->materials->map(fn (ProductionOrderMaterial $line): array => [
                'sales_order_item_id' => $line->sales_order_item_id,
                'product_id' => $line->product_id,
                'product_label' => $line->product_id ? ProductSearch::label($line->product_id) : null,
                'description' => $line->description,
                'pricing_type' => $line->pricing_type,
                'quantity' => Number::clean($line->quantity),
                'weight' => Number::clean($line->weight),
                'unit_cost' => Number::clean($line->unit_cost),
            ])->values()->all(),
            'labor' => $order->labor->map(fn (ProductionOrderLabor $line): array => [
                'worker_id' => $line->worker_id,
                'worker_label' => $line->worker?->name,
                'worker_name' => $line->worker_name,
                'description' => $line->description,
                'rate_basis' => $line->rate_basis,
                'quantity' => Number::clean($line->quantity),
                'rate' => Number::clean($line->rate),
                'work_date' => $line->work_date?->toDateString(),
            ])->values()->all(),
            'services' => $order->services->map(fn (ProductionOrderServiceLine $line): array => [
                'sales_order_item_id' => $line->sales_order_item_id,
                'product_id' => $line->product_id,
                'product_label' => $line->product_id ? ProductSearch::label($line->product_id) : null,
                'description' => $line->description,
                'cost_source' => $line->cost_source,
                'service_provider_id' => $line->service_provider_id,
                'provider_label' => $line->serviceProvider?->name,
                'provider_reference' => $line->provider_reference,
                'pricing_basis' => $line->pricing_basis,
                'quantity' => Number::clean($line->quantity),
                'unit_cost' => Number::clean($line->unit_cost),
                'sent_date' => $line->sent_date?->toDateString(),
                'received_date' => $line->received_date?->toDateString(),
                'notes' => $line->notes,
            ])->values()->all(),
        ];
    }

    /**
     * البنود المقترحة من طلبية البيع بالشكل الذي يقرؤه النموذج: الارقام نصوص، ومعها اسم
     * الصنف المعروض في حقل البحث.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function formLines(array $lines): array
    {
        return array_values(array_map(fn (array $line): array => [
            ...array_map(fn ($value) => is_float($value) ? Number::clean($value) : $value, $line),
            'product_label' => filled($line['product_id'] ?? null) ? ProductSearch::label((int) $line['product_id']) : null,
        ], $lines));
    }

    /**
     * @param  array<int|string, string>  $map
     * @return list<array{value: int, label: string}>
     */
    private function options(array $map): array
    {
        return collect($map)->map(fn ($label, $id): array => ['value' => (int) $id, 'label' => (string) $label])->values()->all();
    }

    /**
     * نص يطابق عموداً في علاقة (رقم طلبية البيع، اسم الزبون).
     */
    private function relationText(string $name, string $relation, string $column): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($relation, $column): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas($relation, fn (Builder $query) => $query->whereLike($column, $term));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'laborBasisOptions' => collect(LaborRateBasis::cases())->map(fn (LaborRateBasis $basis): array => [
                'value' => $basis->value,
                'label' => $basis->getLabel(),
                'unit' => $basis->unitLabel(),
            ])->all(),
            'costSourceOptions' => SelectOptions::from(ServiceCostSource::options()),
            'pricingBasisOptions' => collect(ServicePricingBasis::cases())->map(fn (ServicePricingBasis $basis): array => [
                'value' => $basis->value,
                'label' => $basis->getLabel(),
                'unit' => $basis->unitLabel(),
            ])->all(),
        ];
    }
}
