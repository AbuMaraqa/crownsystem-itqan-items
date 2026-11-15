<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Repositories;
use Modules\Accounting\Repositories\Contracts;

class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        Contracts\AccountMappingRepositoryInterface::class => Repositories\AccountMappingRepository::class,
        Contracts\AccountRepositoryInterface::class => Repositories\AccountRepository::class,
        Contracts\AdditionalCostTypeRepositoryInterface::class => Repositories\AdditionalCostTypeRepository::class,
        Contracts\AttachmentCategoryRepositoryInterface::class => Repositories\AttachmentCategoryRepository::class,
        Contracts\ChequeRepositoryInterface::class => Repositories\ChequeRepository::class,
        Contracts\ClearanceAttachmentTypeRepositoryInterface::class => Repositories\ClearanceAttachmentTypeRepository::class,
        Contracts\CurrencyExchangeRateRepositoryInterface::class => Repositories\CurrencyExchangeRateRepository::class,
        Contracts\CustomerGroupRepositoryInterface::class => Repositories\CustomerGroupRepository::class,
        Contracts\DeliveryCostElementRepositoryInterface::class => Repositories\DeliveryCostElementRepository::class,
        Contracts\FiscalYearRepositoryInterface::class => Repositories\FiscalYearRepository::class,
        Contracts\JournalEntryRepositoryInterface::class => Repositories\JournalEntryRepository::class,
        Contracts\PaymentAllocationRepositoryInterface::class => Repositories\PaymentAllocationRepository::class,
        Contracts\PaymentVoucherRepositoryInterface::class => Repositories\PaymentVoucherRepository::class,
        Contracts\PeriodClosingVoucherRepositoryInterface::class => Repositories\PeriodClosingVoucherRepository::class,
        Contracts\ReappraisalElementRepositoryInterface::class => Repositories\ReappraisalElementRepository::class,
        Contracts\ReceiptVoucherRepositoryInterface::class => Repositories\ReceiptVoucherRepository::class,
        Contracts\SalesQuotationRepositoryInterface::class => Repositories\SalesQuotationRepository::class,
        Contracts\SalesOrderRepositoryInterface::class => Repositories\SalesOrderRepository::class,
        Contracts\SalesInvoiceRepositoryInterface::class => Repositories\SalesInvoiceRepository::class,
        Contracts\SalesReturnRepositoryInterface::class => Repositories\SalesReturnRepository::class,
        Contracts\PurchaseQuotationRepositoryInterface::class => Repositories\PurchaseQuotationRepository::class,
        Contracts\PurchaseOrderRepositoryInterface::class => Repositories\PurchaseOrderRepository::class,
        Contracts\PurchaseInvoiceRepositoryInterface::class => Repositories\PurchaseInvoiceRepository::class,
        Contracts\PurchaseReturnRepositoryInterface::class => Repositories\PurchaseReturnRepository::class,
        Contracts\ProductionOrderRepositoryInterface::class => Repositories\ProductionOrderRepository::class,
        Contracts\ConsolidatedPurchaseOrderRepositoryInterface::class => Repositories\ConsolidatedPurchaseOrderRepository::class,
        Contracts\GoodsReceiptRepositoryInterface::class => Repositories\GoodsReceiptRepository::class,
        Contracts\DeliveryNoteRepositoryInterface::class => Repositories\DeliveryNoteRepository::class,
        Contracts\StockTransferRepositoryInterface::class => Repositories\StockTransferRepository::class,
        Contracts\StockAdjustmentRepositoryInterface::class => Repositories\StockAdjustmentRepository::class,
        Contracts\SupplierRepositoryInterface::class => Repositories\SupplierRepository::class,
        Contracts\TaxRepositoryInterface::class => Repositories\TaxRepository::class,
        Contracts\WarehouseRepositoryInterface::class => Repositories\WarehouseRepository::class,
    ];
}
