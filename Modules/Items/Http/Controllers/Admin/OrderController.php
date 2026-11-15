<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Items\Entities\Order;
use Modules\Items\Entities\OrderItem;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\OrderStatus;
use Modules\Items\Enums\PaymentMethod;
use Modules\Items\Enums\PaymentStatus;
use Modules\Items\Http\Requests\Admin\OrderRequest;
use Modules\Items\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Items\Services\OrderService;
use Modules\Items\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * طلبات المتجر في لوحة Vue: القائمة، والاضافة، والتعديل، والحذف.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderService $orderService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Order/Index', [
            'table' => AdminTable::for($this->orders->tableQuery())
                ->filters(
                    AdminFilter::text('order_number'),
                    AdminFilter::exact('payment_status'),
                    AdminFilter::exact('status'),
                    AllowedFilter::callback('name', fn ($query, mixed $value) => $query->whereHas('user', fn ($query) => $query->whereLike('name', '%'.addcslashes(trim((string) $value), '%_\\').'%'))),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('order_number', 'sub_total', 'discount', 'delivery_fee', 'total', 'status', 'payment_status', 'created_at', 'id')
                ->rows(fn (Order $order) => [
                    'id' => (int) $order->id,
                    'order_number' => $order->order_number,
                    'user' => UserCellData::of($order->user),
                    'sub_total' => $order->sub_total,
                    'discount' => $order->discount,
                    'delivery_fee' => $order->delivery_fee,
                    'total' => $order->total,
                    'status' => StatusBadge::of($order->status),
                    'payment_status' => StatusBadge::of($order->payment_status),
                    'payment_method' => $order->payment_method?->getLabel(),
                    'delivery_address' => $order->delivery_address,
                    'contact_phone' => $order->contact_phone,
                    'notes' => $order->notes,
                    'created_at' => $order->created_at?->format('d/m/Y H:i'),
                    'items' => $order->items->map(fn (OrderItem $item) => [
                        'name' => $item->product?->name,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'total_price' => $item->total_price,
                    ])->values(),
                ]),
            'statuses' => SelectOptions::from(OrderStatus::options()),
            'paymentStatuses' => SelectOptions::from(PaymentStatus::options()),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Items/Order/Add', [
            ...$this->formOptions(),
            'labels' => ['customers' => [], 'cities' => [], 'districts' => [], 'products' => []],
        ]);
    }

    public function store(OrderRequest $request): RedirectResponse
    {
        $this->orderService->create($request->validated(), $request->user()->id);

        return to_route('orders.index')->with('success', __('Order added successfully'));
    }

    public function edit(int $order): Response
    {
        $model = $this->orders->forForm($order);

        return Inertia::render('Items/Order/Edit', [
            ...$this->formOptions(),
            'order' => [
                'id' => (int) $model->id,
                'order_number' => $model->order_number,
                'user_id' => $model->user_id,
                'branch_id' => $model->branch_id,
                'city_id' => $model->city_id,
                'district_id' => $model->district_id,
                'delivery_fee' => (float) $model->delivery_fee,
                'status' => $model->status?->value,
                'payment_method' => $model->payment_method?->value,
                'payment_status' => $model->payment_status?->value,
                'delivery_address' => $model->delivery_address,
                'contact_phone' => $model->contact_phone,
                'notes' => $model->notes,
                'items' => $model->items->map(fn (OrderItem $item) => [
                    'id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'price' => (float) $item->price,
                ])->values(),
            ],
            'labels' => [
                'customers' => $this->orders->labels('customers', [(int) $model->user_id]),
                'cities' => $this->orders->labels('cities', [(int) $model->city_id]),
                'districts' => $this->orders->labels('districts', [(int) $model->district_id]),
                'products' => $this->orders->labels('products', $model->items->pluck('product_id')->map(fn ($id) => (int) $id)->all()),
            ],
        ]);
    }

    public function update(OrderRequest $request, int $order): RedirectResponse
    {
        $this->orderService->update($this->orders->forForm($order), $request->validated());

        return to_route('orders.index')->with('success', __('Order updated successfully'));
    }

    public function destroy(int $order): RedirectResponse
    {
        /** @var Order $model */
        $model = $this->orders->findOrFail($order);
        $this->orderService->delete($model);

        return back()->with('success', __('Order deleted successfully'));
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $this->orderService->deleteMany($ids);

        return back()->with('success', __('Deleted successfully'));
    }

    public function info(int $order): JsonResponse
    {
        return response()->json(RecordInfo::of($this->orders->findOrFail($order)));
    }

    /**
     * بحث حقول النموذج (العميل، الصنف، المدينة، الحي) مع سعر الصنف الاساسي للمنتجات.
     */
    public function lookup(Request $request, string $type): JsonResponse
    {
        $options = $this->orders->lookup($type, (string) $request->query('q', ''));

        if ($type === 'products') {
            $prices = Product::query()->whereKey(array_column($options, 'value'))->pluck('base_price', 'id');
            $options = array_map(fn (array $option): array => [...$option, 'price' => (float) ($prices[$option['value']] ?? 0)], $options);
        }

        return response()->json($options);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'branches' => SelectOptions::branches(),
            'statuses' => SelectOptions::from(OrderStatus::options()),
            'paymentMethods' => SelectOptions::from(PaymentMethod::options()),
            'paymentStatuses' => SelectOptions::from(PaymentStatus::options()),
        ];
    }
}
