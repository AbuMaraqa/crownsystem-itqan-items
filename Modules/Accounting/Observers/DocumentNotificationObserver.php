<?php

namespace Modules\Accounting\Observers;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Core\Services\NotificationService;

/**
 * يرسل اشعاراً للمستخدم الموجه له المستند (العميل/المورد في client_id، او المتابع في طلبية الشراء التجميعية):
 * - عند انشاء المستند.
 * - عند تحويل المستند لمستخدم آخر (يصل الاشعار للمستخدم الجديد).
 * - عند تغيير حالة المستند (اعتماد، ترحيل، الغاء ...).
 *
 * يعمل بعد اعتماد المعاملة (after commit) حتى لا يصل اشعار عن مستند تراجع حفظه.
 * لاضافة مستند جديد يكفي اضافته الى DOCUMENTS.
 */
class DocumentNotificationObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * المستند => اسمه (مفتاح ترجمة)، مسار فتحه، اعمدة المستلمين، ونوع الحالة.
     *
     * @var array<class-string<Model>, array{label: string, route: string, recipients: array<string>, status: class-string<BackedEnum>}>
     */
    public const DOCUMENTS = [
        SalesQuotation::class => ['label' => 'Sales Quotation', 'route' => 'accounting.sales-quotations.edit', 'recipients' => ['client_id'], 'status' => SalesQuotationStatus::class],
        SalesOrder::class => ['label' => 'Sales Order', 'route' => 'accounting.sales-orders.edit', 'recipients' => ['client_id'], 'status' => SalesOrderStatus::class],
        SalesInvoice::class => ['label' => 'Sales Invoice', 'route' => 'accounting.sales-invoices.edit', 'recipients' => ['client_id'], 'status' => SalesInvoiceStatus::class],
        SalesReturn::class => ['label' => 'Sales Return', 'route' => 'accounting.sales-returns.edit', 'recipients' => ['client_id'], 'status' => SalesReturnStatus::class],
        PurchaseOrder::class => ['label' => 'Purchase Order', 'route' => 'accounting.purchase-orders.edit', 'recipients' => ['client_id'], 'status' => PurchaseOrderStatus::class],
        ConsolidatedPurchaseOrder::class => ['label' => 'Consolidated Purchase Order', 'route' => 'accounting.consolidated-purchase-orders.edit', 'recipients' => ['client_id', 'followed_up_by'], 'status' => ConsolidatedPurchaseOrderStatus::class],
        PurchaseInvoice::class => ['label' => 'Purchase Invoice', 'route' => 'accounting.purchase-invoices.edit', 'recipients' => ['client_id'], 'status' => PurchaseInvoiceStatus::class],
        PurchaseReturn::class => ['label' => 'Purchase Return', 'route' => 'accounting.purchase-returns.edit', 'recipients' => ['client_id'], 'status' => PurchaseReturnStatus::class],
        ProductionOrder::class => ['label' => 'Production Order', 'route' => 'accounting.production-orders.edit', 'recipients' => ['client_id'], 'status' => ProductionOrderStatus::class],
        ReceiptVoucher::class => ['label' => 'Receipt Voucher', 'route' => 'accounting.receipt-vouchers.edit', 'recipients' => ['client_id'], 'status' => ReceiptVoucherStatus::class],
        PaymentVoucher::class => ['label' => 'Payment Voucher', 'route' => 'accounting.payment-vouchers.edit', 'recipients' => ['client_id'], 'status' => PaymentVoucherStatus::class],
        DeliveryNote::class => ['label' => 'Delivery Note', 'route' => 'accounting.inventory.delivery-notes.edit', 'recipients' => ['client_id'], 'status' => InventoryDocumentStatus::class],
        GoodsReceipt::class => ['label' => 'Goods Receipt', 'route' => 'accounting.inventory.goods-receipts.edit', 'recipients' => ['client_id'], 'status' => InventoryDocumentStatus::class],
    ];

    public function __construct(protected NotificationService $notifications) {}

    public static function register(): void
    {
        foreach (array_keys(self::DOCUMENTS) as $model) {
            $model::observe(self::class);
        }
    }

    public function created(Model $document): void
    {
        $this->notify($document, $this->recipients($document), 'document.sent',
            'New :document', ':document #:reference has been sent to you by :sender.', 'info');
    }

    public function updated(Model $document): void
    {
        // انشاء ثم تعديل في نفس الطلب (مثل حفظ الاجماليات): اشعار الانشاء يكفي.
        if ($document->wasRecentlyCreated) {
            return;
        }

        $config = self::DOCUMENTS[$document::class];
        $reassignedTo = [];

        foreach ($config['recipients'] as $column) {
            if ($document->wasChanged($column) && $document->getAttribute($column)) {
                $reassignedTo[] = (int) $document->getAttribute($column);
            }
        }

        if ($reassignedTo !== []) {
            $this->notify($document, $reassignedTo, 'document.sent',
                'New :document', ':document #:reference has been sent to you by :sender.', 'info');
        }

        if ($document->wasChanged('status')) {
            $status = $this->statusCase($document);

            if ($status) {
                // من حُوّل له المستند للتو وصله اشعار الارسال، فلا داعي لاشعار الحالة ايضاً.
                $this->notify($document, array_diff($this->recipients($document), $reassignedTo), 'document.status_changed',
                    ':document status updated', ':document #:reference is now :status.', 'warning',
                    ['status' => NotificationService::enum($status)]);
            }
        }
    }

    /**
     * @param  array<int>  $recipients
     */
    protected function notify(Model $document, array $recipients, string $event, string $title, string $message, string $color, array $params = []): void
    {
        if ($recipients === []) {
            return;
        }

        $config = self::DOCUMENTS[$document::class];

        $this->notifications->send(
            recipients: $recipients,
            title: $title,
            message: $message,
            params: $params + [
                'document' => NotificationService::trans($config['label']),
                'reference' => $document->getAttribute('reference_no') ?: $document->getKey(),
            ],
            url: Route::has($config['route']) ? route($config['route'], $document) : null,
            subject: $document,
            event: $event,
            icon: 'document',
            color: $color,
        );
    }

    /**
     * @return array<int>
     */
    protected function recipients(Model $document): array
    {
        return collect(self::DOCUMENTS[$document::class]['recipients'])
            ->map(fn (string $column) => (int) $document->getAttribute($column))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function statusCase(Model $document): ?BackedEnum
    {
        $status = $document->getAttribute('status');

        if ($status instanceof BackedEnum) {
            return $status;
        }

        return $status === null ? null : self::DOCUMENTS[$document::class]['status']::tryFrom((int) $status);
    }
}
