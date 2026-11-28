<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Requests\Admin\ConsolidatedPurchaseOrderHeaderRequest;
use Modules\Accounting\Http\Requests\Admin\ConsolidatedPurchaseOrderRequest;
use Modules\Accounting\Repositories\Contracts\ConsolidatedPurchaseOrderRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderPickerService;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Support\ConsolidatedPurchaseOrderTabs;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PurchaseOrderConsumption;
use Modules\Accounting\Support\QuickCreate;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Branch\Enums\BranchStatus;
use Modules\Core\Entities\User;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * الطلبيات التجميعية: بنود تُسحب من طلبيات شراء (اصناف فرعية تحت صنفها الرئيسي)، ثم صفحة
 * بتبويبات: الاصناف، الشحن، التأمين، التخليص، التكاليف الاضافية، اعادة التخمين، التسليم،
 * المرفقات، الملاحظات (وعروض الاسعار والترسية والملف المالي مخفية مؤقتاً). سجلات التبويبات في
 * ConsolidatedPurchaseOrderTabController؛ هنا الطلبية ونافذة السحب.
 */
class ConsolidatedPurchaseOrderController extends Controller
{
    use DeletesManyDocuments;

    /**
     * دور كل نوع طرف يُختار او يُنشأ من نماذج الصفحة.
     */
    private const PARTY_ROLES = [
        'supplier' => 'Supplier',
        'shipping' => 'Shipping Company',
        'insurance' => 'Insurance Company',
        'clearance' => 'Clearance Company',
        'delivery' => 'Delivery Company',
    ];

    public function __construct(
        private readonly ConsolidatedPurchaseOrderRepositoryInterface $orders,
        private readonly ConsolidatedPurchaseOrderService $service,
        private readonly ConsolidatedPurchaseOrderPickerService $picker,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/ConsolidatedPurchaseOrder/Index', [
            'table' => AdminTable::for($this->orders->tableQuery())
                ->search()
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->supplierFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('order_date'),
                    ...AdminFilter::dateRange('expected_delivery_date'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'order_date', 'expected_delivery_date', 'total_amount', 'status', 'id')
                ->defaultSort('-order_date')
                ->withCreator()
                ->rows(fn (ConsolidatedPurchaseOrder $order) => [
                    'id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'order_date' => $order->order_date?->format('d/m/Y'),
                    'expected_delivery_date' => $order->expected_delivery_date?->format('d/m/Y'),
                    'client' => UserCellData::of($order->client),
                    'warehouse' => $order->warehouse?->name,
                    'currency' => $order->currency?->code,
                    'total_amount' => number_format((float) $order->total_amount, 2),
                    'total_amount_full' => Number::money($order->total_amount),
                    'status' => StatusBadge::of($order->status),
                    'status_value' => $order->status?->value,
                    'notes' => $order->notes,
                    'deleted' => $order->trashed(),
                ]),
            'statusOptions' => SelectOptions::from(ConsolidatedPurchaseOrderStatus::options()),
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'currencyOptions' => SelectOptions::currencies(),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Accounting/ConsolidatedPurchaseOrder/Add', [
            'order' => [
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => null,
                'items' => [],
            ],
            'branchClosed' => auth()->user()?->branch?->status === BranchStatus::CLOSED,
        ]);
    }

    /**
     * الطلبية بعملة الاساس وسعر صرفها يوم الطلبية، وكل كمية ضمن المتبقي من بندها في طلبية الشراء.
     */
    public function store(ConsolidatedPurchaseOrderRequest $request, CurrencyExchangeRateService $rates): RedirectResponse
    {
        $data = $request->validated();
        $currencyId = $rates->baseCurrencyId();

        try {
            // التحقق والحفظ في معاملة واحدة: قفل بنود المصدر يبقى حتى تُنشأ الطلبية.
            $order = DB::transaction(function () use ($data, $currencyId, $rates) {
                PurchaseOrderConsumption::for()->assertRowQuantities($data['items']);

                return $this->service->create([
                    ...$data,
                    'currency_id' => $currencyId,
                    'exchange_rate' => $rates->rateFor($currencyId, $data['order_date']) ?? 1,
                ]);
            });
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.consolidated-purchase-orders.edit', $order->id)->with('success', __('Consolidated purchase order created successfully'));
    }

    public function edit(int $consolidatedPurchaseOrder): Response
    {
        $order = $this->orders->findForPage($consolidatedPurchaseOrder);

        return Inertia::render('Accounting/ConsolidatedPurchaseOrder/Edit', [
            'order' => [
                'order_date' => $order->order_date?->toDateString(),
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'followed_up_by' => $order->followed_up_by,
                'notes' => $order->notes,
            ],
            'record' => [
                'id' => $order->id,
                'reference_no' => $order->reference_no,
                'status' => StatusBadge::of($order->status),
                'client' => $order->client?->name,
                'currency' => $order->currency?->code,
                'total_amount' => Number::money($order->total_amount),
                'created_by' => $order->createdBy?->name,
                'followed_up_by' => $order->followedUpBy ? ['value' => $order->followedUpBy->id, 'label' => (string) $order->followedUpBy->name] : null,
                'currency_id' => $order->currency_id,
            ],
            'counts' => $order->tabCounts(),
            'blankOfferItems' => ConsolidatedPurchaseOrderTabs::blankOfferItems($order),
            'activity' => RecordInfo::of($order),
            ...ConsolidatedPurchaseOrderTabs::all($order),
            ...ConsolidatedPurchaseOrderTabs::options(),
        ]);
    }

    public function update(ConsolidatedPurchaseOrderHeaderRequest $request, int $consolidatedPurchaseOrder): RedirectResponse
    {
        try {
            $this->service->updateHeader($this->find($consolidatedPurchaseOrder), $request->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Consolidated purchase order updated successfully'));
    }

    public function changeStatus(Request $request, int $consolidatedPurchaseOrder): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(ConsolidatedPurchaseOrderStatus::class)]], [], ['status' => __('Status')]);

        try {
            $this->service->changeStatus($this->find($consolidatedPurchaseOrder), ConsolidatedPurchaseOrderStatus::from((int) $data['status']));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Consolidated purchase order status updated successfully'));
    }

    public function destroy(int $consolidatedPurchaseOrder): RedirectResponse
    {
        try {
            $this->service->delete($this->find($consolidatedPurchaseOrder));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.consolidated-purchase-orders.index')->with('success', __('Consolidated purchase order deleted successfully'));
    }

    /**
     * The list's rows, deleted ones included (its «deleted» filter shows them; they are refused).
     */
    protected function bulkDeleteQuery(): Builder
    {
        return $this->orders->tableQuery()->withTrashed();
    }

    /**
     * @param  ConsolidatedPurchaseOrder  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    /**
     * The list's «Info» window; a deleted order stays in the list, so it is found too.
     */
    public function info(int $consolidatedPurchaseOrder): JsonResponse
    {
        return response()->json(RecordInfo::of($this->orders->query()->withTrashed()->findOrFail($consolidatedPurchaseOrder)));
    }

    /**
     * نموذج طلب عرض سعر من مورد (PDF).
     */
    public function quoteRequest(ConsolidatedPurchaseOrder $consolidatedPurchaseOrder, User $supplier, AccountingPdfService $pdf): mixed
    {
        return $pdf->consolidatedPurchaseOrderQuoteRequest($consolidatedPurchaseOrder, $supplier);
    }

    /* ----------------------------- نافذة السحب ----------------------------- */

    /**
     * طلبيات الشراء المتاحة للسحب منها. `order` الطلبية الحالية عند التعديل.
     */
    public function purchaseOrders(Request $request): JsonResponse
    {
        return response()->json($this->picker->purchaseOrderOptions($request->string('q')->toString(), $request->integer('order') ?: null));
    }

    /**
     * بنود طلبية شراء في النافذة: صفحة الاضافة ترسل صفوفها (`rows`)، وصفحة التعديل رقم طلبيتها (`order`).
     */
    public function purchaseOrderItems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer'],
            'order' => ['nullable', 'integer'],
            'rows' => ['nullable', 'array'],
            'q' => ['nullable', 'string', 'max:255'],
            'length' => ['nullable'],
            'quantity' => ['nullable'],
            'selected' => ['nullable', 'array'],
        ]);

        $order = filled($data['order'] ?? null) ? $this->find((int) $data['order']) : null;
        $pulled = $order ? $this->picker->pulledFromOrder($order) : $this->picker->pulledFromRows((array) ($data['rows'] ?? []));

        return response()->json([
            'label' => $this->picker->purchaseOrderLabel((int) $data['purchase_order_id']),
            'items' => $this->picker->mainItems(
                (int) $data['purchase_order_id'],
                $pulled,
                $order?->id,
                $data['q'] ?? '',
                $data['length'] ?? null,
                $data['quantity'] ?? null,
                array_map('strval', (array) ($data['selected'] ?? [])),
            ),
        ]);
    }

    /**
     * صفحة الاضافة: يضيف المؤشَّر (او صنف الكمية بكميته) الى صفوفها ويعيدها.
     */
    public function pullRows(Request $request): JsonResponse
    {
        $data = $this->pullData($request, ['rows' => ['nullable', 'array']]);

        try {
            $result = $this->picker->pullIntoRows((array) ($data['rows'] ?? []), (array) ($data['keys'] ?? []), $data['purchase_order_item_id'] ?? null, $data['quantity'] ?? null);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([...$result, 'message' => isset($data['purchase_order_item_id']) ? __('Item added.') : $this->pullMessage($result)]);
    }

    /**
     * صفحة التعديل: يحفظ المؤشَّر في الطلبية فوراً، تحت بند صنفه الرئيسي ان وُجد.
     */
    public function pullItems(Request $request, int $consolidatedPurchaseOrder): RedirectResponse
    {
        $data = $this->pullData($request);

        try {
            $result = $this->picker->pullIntoOrder($this->find($consolidatedPurchaseOrder), (array) ($data['keys'] ?? []), $data['purchase_order_item_id'] ?? null, $data['quantity'] ?? null);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = isset($data['purchase_order_item_id']) ? __('Item added.') : $this->pullMessage($result);

        return $result['added'] > 0 ? back()->with('success', $message) : back()->with('error', $message);
    }

    public function updateItem(Request $request, int $consolidatedPurchaseOrder, int $item): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0.0001']], [], ['quantity' => __('Quantity')]);

        try {
            $this->picker->updateQuantity($this->find($consolidatedPurchaseOrder)->items()->findOrFail($item), $data['quantity']);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Item updated successfully'));
    }

    public function destroyItem(int $consolidatedPurchaseOrder, int $item): RedirectResponse
    {
        $this->service->deleteItem($this->find($consolidatedPurchaseOrder)->items()->findOrFail($item));

        return back()->with('success', __('Item deleted successfully'));
    }

    /* ----------------------------- الاطراف والقوائم ----------------------------- */

    /**
     * بحث الاطراف بدورها (مورّد، شركة شحن/تأمين/تخليص/توصيل)، او كل المستخدمين (`user`).
     */
    public function parties(Request $request): JsonResponse
    {
        $role = self::PARTY_ROLES[$request->string('role')->toString()] ?? null;
        $term = trim($request->string('q')->toString());

        return response()->json(User::query()
            ->when($role, fn (Builder $query) => $query->whereHas('roles', fn (Builder $query) => $query->where('name', $role)))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => (string) $user->name])
            ->all());
    }

    /**
     * طرف جديد بدوره، يُنشأ من النموذج نفسه بدل الخروج لصفحة المستخدمين وفقدان ما كُتب.
     */
    public function storeParty(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(self::PARTY_ROLES))],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => __('Name'), 'email' => __('Email'), 'phone' => __('Phone')]);

        $id = QuickCreate::party($data, self::PARTY_ROLES[$data['role']]);

        return response()->json(['value' => $id, 'label' => $data['name']]);
    }

    /**
     * بند تكلفة او تصنيف مرفقات او بند اعادة تخمين جديد، من الحقل نفسه.
     */
    public function storeLookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['additional-cost-type', 'attachment-category', 'reappraisal-element'])],
            'name' => ['required', 'string', 'max:255'],
        ], [], ['name' => __('Name')]);

        $record = match ($data['type']) {
            'additional-cost-type' => AdditionalCostType::create(['name' => $data['name'], 'is_active' => true, 'created_by' => auth()->id()]),
            'attachment-category' => AttachmentCategory::create(['name' => $data['name'], 'created_by' => auth()->id()]),
            'reappraisal-element' => ReappraisalElement::create(['name' => $data['name'], 'is_active' => true, 'created_by' => auth()->id()]),
        };

        return response()->json(['value' => (int) $record->id, 'label' => (string) $record->name]);
    }

    private function find(int $id): ConsolidatedPurchaseOrder
    {
        /** @var ConsolidatedPurchaseOrder */
        return $this->orders->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pullData(Request $request, array $extra = []): array
    {
        return $request->validate([
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string'],
            'purchase_order_item_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric'],
            ...$extra,
        ], [], ['quantity' => __('Quantity')]);
    }

    /**
     * @param  array{added: int, skipped: int}  $result
     */
    private function pullMessage(array $result): string
    {
        $messages = [];

        if ($result['added'] > 0) {
            $messages[] = __(':count sub-items added.', ['count' => $result['added']]);
        }

        if ($result['skipped'] > 0) {
            $messages[] = __(':count items were skipped because they are already added.', ['count' => $result['skipped']]);
        }

        return implode(' ', $messages);
    }

    /**
     * المورد بالاسم او الهاتف او البريد.
     */
    private function supplierFilter(): AllowedFilter
    {
        return AllowedFilter::callback('client', function (Builder $query, mixed $value): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas('client', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', $term)
                ->orWhereLike('phone', $term)
                ->orWhereLike('email', $term)));
        });
    }
}
