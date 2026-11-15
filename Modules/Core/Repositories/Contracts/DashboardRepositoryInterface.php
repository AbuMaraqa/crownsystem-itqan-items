<?php

namespace Modules\Core\Repositories\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Items\Entities\Order;
use Spatie\Permission\Models\Role;

/**
 * الارقام خلف لوحة البداية، كلها تُحسب من قاعدة البيانات.
 */
interface DashboardRepositoryInterface
{
    public function usersCount(): int;

    /** المستخدمون بدور Customer. */
    public function clientsCount(): int;

    public function productsCount(): int;

    public function ordersCount(): int;

    public function branchesCount(): int;

    /** الطلبات قيد الانتظار. */
    public function pendingOrdersCount(): int;

    /** الاصناف الرئيسية التي بلغت حدها الادنى في المخزون. */
    public function lowStockCount(): int;

    /**
     * @return Collection<string, int> العدد لكل شهر `YYYY-MM`؛ الشهر بلا طلبات غير موجود
     */
    public function ordersByMonth(Carbon $from): Collection;

    /**
     * @return Collection<string, int> العدد لكل شهر `YYYY-MM`
     */
    public function usersByMonth(Carbon $from): Collection;

    /**
     * @return Collection<int, int> العدد لكل حالة طلب
     */
    public function ordersByStatus(): Collection;

    /**
     * @return Collection<int, Role>
     */
    public function rolesWithUsersCount(): Collection;

    /**
     * @return Collection<int, Order>
     */
    public function recentOrders(int $limit): Collection;
}
