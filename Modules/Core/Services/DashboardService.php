<?php

namespace Modules\Core\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Support\ConsolidatedPurchaseOrderCalendar;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\Contracts\DashboardRepositoryInterface;
use Modules\Items\Entities\Order;
use Modules\Items\Enums\OrderStatus;
use Spatie\Permission\Models\Role;

/**
 * ما يراه المستخدم على لوحة البداية. الودجت التي لا يملك صلاحيتها تكون null ولا تُحسب ارقامها
 * اصلاً، فلا تصل الى المتصفح.
 */
class DashboardService
{
    private const MONTHS = 12;

    private const RECENT_ORDERS = 5;

    /** الاختصارات السريعة. */
    private const QUICK_LINKS = [
        ['key' => 'orders', 'label' => 'Orders List', 'route' => 'orders.index', 'permission' => 'Order View List'],
        ['key' => 'products', 'label' => 'Products List', 'route' => 'products.index', 'permission' => 'Product View List'],
        ['key' => 'add_product', 'label' => 'Add Product', 'route' => 'products.add', 'permission' => 'Product Create'],
        ['key' => 'categories', 'label' => 'Categories List', 'route' => 'categories.index', 'permission' => 'Category View List'],
        ['key' => 'branches', 'label' => 'Branches List', 'route' => 'branches.index', 'permission' => 'Branch View List'],
        ['key' => 'users', 'label' => 'Users List', 'route' => 'users.index', 'permission' => 'User View List'],
    ];

    public function __construct(private readonly DashboardRepositoryInterface $dashboard) {}

    /**
     * @return array<string, mixed>
     */
    public function widgets(User $user): array
    {
        $canUsers = $user->can('User View List');
        $canOrders = $user->can('Order View List');

        return [
            'metrics' => $this->metrics($user, $canUsers, $canOrders),
            'quickLinks' => $this->quickLinks($user),
            'ordersPerMonth' => $canOrders ? $this->perMonth($this->dashboard->ordersByMonth($this->from())) : null,
            'usersByRole' => $canUsers ? $this->usersByRole() : null,
            'newUsersPerMonth' => $canUsers ? $this->perMonth($this->dashboard->usersByMonth($this->from())) : null,
            'ordersByStatus' => $canOrders ? $this->ordersByStatus() : null,
            'recentOrders' => $canOrders ? $this->recentOrders($user) : null,
            'alerts' => $this->alerts($user, $canOrders),
            'purchaseOrderCalendar' => $user->can('Consolidated Purchase Order View List') && Route::has('dashboard.purchase-order-events')
                ? ['url' => route('dashboard.purchase-order-events'), 'types' => $this->calendarTypes()]
                : null,
        ];
    }

    /**
     * كل تواريخ طلبيات الشراء التجميعية في المدى، حدثاً لكل تاريخ.
     *
     * @return list<array{id: string, type: string, date: string, title: string, url: string, client: ?string}>
     */
    public function purchaseOrderEvents(Carbon $from, Carbon $to): array
    {
        $events = [];

        foreach (ConsolidatedPurchaseOrderCalendar::eventsBetween($from, $to) as $date => $dayEvents) {
            foreach ($dayEvents as $event) {
                $events[] = [
                    'id' => $event['type'].'-'.$date.'-'.$event['reference'],
                    'type' => $event['type'],
                    'date' => $date,
                    'title' => $event['reference'].' — '.$event['label'],
                    'url' => $event['url'],
                    'client' => $event['client'],
                ];
            }
        }

        return $events;
    }

    /**
     * @return list<array{key: string, label: string, color: string, tint: string}>
     */
    private function calendarTypes(): array
    {
        return collect(ConsolidatedPurchaseOrderCalendar::types())
            ->map(fn (array $type, string $key) => ['key' => $key, ...$type])
            ->values()
            ->all();
    }

    /**
     * ما يحتاج تدخلاً الان، كل تنبيه يفتح القائمة التي تعرضه.
     *
     * @return list<array{key: string, title: string, value: int, url: ?string}>
     */
    private function alerts(User $user, bool $canOrders): array
    {
        $url = fn (string $route) => Route::has($route) ? route($route) : null;
        $alerts = [];

        if ($canOrders) {
            $alerts[] = ['key' => 'pending_orders', 'title' => __('Pending Orders'), 'value' => $this->dashboard->pendingOrdersCount(), 'url' => $url('orders.index')];
        }

        if ($user->can('Product Low Stock View List')) {
            $alerts[] = ['key' => 'low_stock', 'title' => __('Low stock'), 'value' => $this->dashboard->lowStockCount(), 'url' => $url('products.index')];
        }

        return $alerts;
    }

    /**
     * @return list<array{key: string, title: string, value: int}>
     */
    private function metrics(User $user, bool $canUsers, bool $canOrders): array
    {
        $metrics = [];

        if ($canUsers) {
            $metrics[] = ['key' => 'users', 'title' => __('Total Users'), 'value' => $this->dashboard->usersCount()];
            $metrics[] = ['key' => 'clients', 'title' => __('Total Clients'), 'value' => $this->dashboard->clientsCount()];
        }

        if ($user->can('Product View List')) {
            $metrics[] = ['key' => 'products', 'title' => __('Total Products'), 'value' => $this->dashboard->productsCount()];
        }

        if ($canOrders) {
            $metrics[] = ['key' => 'orders', 'title' => __('Total Orders'), 'value' => $this->dashboard->ordersCount()];
        }

        if ($user->can('Branch View List')) {
            $metrics[] = ['key' => 'branches', 'title' => __('Total Branches'), 'value' => $this->dashboard->branchesCount()];
        }

        return $metrics;
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    private function quickLinks(User $user): array
    {
        return collect(self::QUICK_LINKS)
            ->filter(fn (array $link) => $user->can($link['permission']) && Route::has($link['route']))
            ->map(fn (array $link) => [
                'key' => $link['key'],
                'label' => __($link['label']),
                'url' => route($link['route']),
            ])
            ->values()
            ->all();
    }

    private function from(): Carbon
    {
        return now()->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);
    }

    /**
     * آخر اثني عشر شهراً، والشهر بلا صفوف صفر.
     *
     * @param  Collection<string, int>  $counts
     * @return array{categories: list<string>, data: list<int>}
     */
    private function perMonth(Collection $counts): array
    {
        $categories = [];
        $data = [];

        for ($month = $this->from(), $i = 0; $i < self::MONTHS; $i++, $month = $month->copy()->addMonthNoOverflow()) {
            $categories[] = $month->copy()->locale(app()->getLocale())->translatedFormat('M Y');
            $data[] = $counts->get($month->format('Y-m'), 0);
        }

        return ['categories' => $categories, 'data' => $data];
    }

    /**
     * @return array{labels: list<string>, data: list<int>}
     */
    private function usersByRole(): array
    {
        $roles = $this->dashboard->rolesWithUsersCount();

        return [
            'labels' => $roles->map(fn (Role $role) => __($role->name))->values()->all(),
            'data' => $roles->map(fn (Role $role) => (int) $role->users_count)->values()->all(),
        ];
    }

    /**
     * الحالات التي لها طلبات، بترتيب سير العمل.
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    private function ordersByStatus(): array
    {
        $counts = $this->dashboard->ordersByStatus();
        $statuses = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $counts->has($status->value)));

        return [
            'labels' => array_map(fn (OrderStatus $status) => $status->getLabel(), $statuses),
            'data' => array_map(fn (OrderStatus $status) => $counts->get($status->value), $statuses),
        ];
    }

    /**
     * @return array{url: string|null, rows: list<array<string, mixed>>}
     */
    private function recentOrders(User $user): array
    {
        $canEdit = $user->can('Order Update') && Route::has('orders.edit');

        return [
            'url' => Route::has('orders.index') ? route('orders.index') : null,
            'rows' => $this->dashboard->recentOrders(self::RECENT_ORDERS)
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->user?->name,
                    'total' => number_format((float) $order->total, 2),
                    'status' => $order->status?->getLabel(),
                    'tone' => $this->tone($order->status),
                    'date' => $order->created_at?->format('Y-m-d H:i'),
                    'url' => $canEdit ? route('orders.edit', $order->id) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /** لون الحالة في Filament الى درجات مكون Badge. */
    private function tone(?OrderStatus $status): string
    {
        return match ($status?->getColor()) {
            'warning' => 'warning',
            'info' => 'info',
            'primary' => 'brand',
            'success' => 'success',
            'danger' => 'danger',
            default => 'neutral',
        };
    }
}
