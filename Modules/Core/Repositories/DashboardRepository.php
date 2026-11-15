<?php

namespace Modules\Core\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\Contracts\DashboardRepositoryInterface;
use Modules\Core\Support\SqlDialect;
use Modules\Items\Entities\Order;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\OrderStatus;
use Spatie\Permission\Models\Role;

class DashboardRepository implements DashboardRepositoryInterface
{
    public function usersCount(): int
    {
        return User::query()->count();
    }

    public function clientsCount(): int
    {
        // whereHas لا يرمي استثناءً ان لم يكن الدور موجوداً بعد (عكس User::role).
        return User::query()->whereHas('roles', fn (Builder $query) => $query->where('name', 'Customer'))->count();
    }

    public function productsCount(): int
    {
        return Product::query()->count();
    }

    public function ordersCount(): int
    {
        return Order::query()->count();
    }

    public function branchesCount(): int
    {
        return Branch::query()->count();
    }

    public function pendingOrdersCount(): int
    {
        return Order::query()->where('status', OrderStatus::PENDING)->count();
    }

    public function lowStockCount(): int
    {
        // نفس شرط تنبيه المخزون: الاصناف الرئيسية فقط.
        return Product::query()->lowStock()->whereNull('parent_id')->count();
    }

    public function ordersByMonth(Carbon $from): Collection
    {
        return $this->countByMonth(Order::query(), $from);
    }

    public function usersByMonth(Carbon $from): Collection
    {
        return $this->countByMonth(User::query(), $from);
    }

    public function ordersByStatus(): Collection
    {
        return Order::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);
    }

    public function rolesWithUsersCount(): Collection
    {
        return Role::query()->withCount('users')->orderBy('name')->get()->toBase();
    }

    public function recentOrders(int $limit): Collection
    {
        return Order::query()->with('user:id,name')->latest('id')->limit($limit)->get()->toBase();
    }

    /**
     * @return Collection<string, int>
     */
    private function countByMonth(Builder $query, Carbon $from): Collection
    {
        $month = SqlDialect::yearMonth('created_at', $query->getConnection());

        return $query
            ->where('created_at', '>=', $from)
            ->selectRaw("{$month} as month, count(*) as total")
            ->groupBy('month')
            ->toBase()
            ->pluck('total', 'month')
            ->map(fn ($total) => (int) $total);
    }
}
