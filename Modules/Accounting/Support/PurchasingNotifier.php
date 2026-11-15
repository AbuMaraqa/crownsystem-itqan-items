<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Entities\SalesOrder;
use Modules\Core\Entities\User;
use Modules\Core\Services\NotificationService;
use Spatie\Permission\Models\Permission;

/**
 * يُشعر قسم المشتريات (كل من يملك صلاحية انشاء طلبية شراء) بطلبية بيع يوردها مورد آخر
 * للزبون مباشرة، ورابط الاشعار يفتح طلبية شراء جديدة معبأة من طلبية البيع.
 */
class PurchasingNotifier
{
    public const PERMISSION = 'Purchase Order Create';

    public const EVENT = 'sales_order.direct_supply';

    public function directSupplyRequested(SalesOrder $order): int
    {
        $order->loadMissing(['client', 'directSupplier']);

        $message = $order->directSupplier
            ? 'Sales order #:reference for customer :customer is supplied directly by :supplier. Create a direct-shipment purchase order for it.'
            : 'Sales order #:reference for customer :customer is supplied directly by a supplier. Create a direct-shipment purchase order for it.';

        return app(NotificationService::class)->send(
            // صلاحية غير مسجلة (قاعدة لم تُزرع صلاحياتها) لا تمنع تأكيد طلبية البيع: لا مستلمين.
            recipients: Permission::query()->where('name', self::PERMISSION)->exists()
                ? User::permission(self::PERMISSION)->get()
                : collect(),
            title: 'Direct supply sales order',
            message: $message,
            params: [
                'reference' => $order->reference_no,
                'customer' => $order->client?->name ?? '-',
                'supplier' => $order->directSupplier?->name,
            ],
            url: route('accounting.purchase-orders.add', ['sales_order_id' => $order->id]),
            subject: $order,
            event: self::EVENT,
            icon: 'shopping-cart',
            color: 'warning',
        );
    }
}
