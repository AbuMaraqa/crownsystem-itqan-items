<?php

use Modules\Accounting\Http\Controllers\Admin\SalesInvoiceController;
use Modules\Accounting\Http\Controllers\Admin\SalesOrderController;
use Modules\Accounting\Http\Controllers\Admin\SalesReturnController;
use Modules\Accounting\Http\Controllers\Admin\PurchaseInvoiceController;
use Modules\Accounting\Http\Controllers\Admin\PurchaseOrderController;
use Modules\Accounting\Http\Controllers\Admin\PurchaseQuotationController;
use Modules\Accounting\Http\Controllers\Admin\PurchaseReturnController;
use Modules\Accounting\Http\Controllers\Admin\AccountController;
use Modules\Accounting\Http\Controllers\Admin\AccountMappingController;
use Modules\Accounting\Http\Controllers\Admin\AdditionalCostTypeController;
use Modules\Accounting\Http\Controllers\Admin\AttachmentCategoryController;
use Modules\Accounting\Http\Controllers\Admin\ChequeController;
use Modules\Accounting\Http\Controllers\Admin\ClearanceAttachmentTypeController;
use Modules\Accounting\Http\Controllers\Admin\CurrencyExchangeRateController;
use Modules\Accounting\Http\Controllers\Admin\CustomerGroupController;
use Modules\Accounting\Http\Controllers\Admin\DeliveryCostElementController;
use Modules\Accounting\Http\Controllers\Admin\FiscalYearController;
use Modules\Accounting\Http\Controllers\Admin\JournalEntryController;
use Modules\Accounting\Http\Controllers\Admin\PaymentAllocationController;
use Modules\Accounting\Http\Controllers\Admin\PaymentVoucherController;
use Modules\Accounting\Http\Controllers\Admin\PeriodClosingVoucherController;
use Modules\Accounting\Http\Controllers\Admin\ReappraisalElementController;
use Modules\Accounting\Http\Controllers\Admin\ReceiptVoucherController;
use Modules\Accounting\Http\Controllers\Admin\SupplierController;
use Modules\Accounting\Http\Controllers\Admin\TaxController;
use Modules\Accounting\Http\Controllers\Admin\WarehouseController;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Accounting\Http\Controllers\AccountingPrintController;
use Modules\Accounting\Http\Controllers\Admin\ConsolidatedPurchaseOrderController;
use Modules\Accounting\Http\Controllers\Admin\ConsolidatedPurchaseOrderTabController;
use Modules\Accounting\Http\Controllers\Admin\ProductionOrderController;
use Modules\Accounting\Http\Middleware\AuthorizeAnyPermission;
use Modules\Accounting\Http\Requests\Admin\ConsolidatedPurchaseOrderTabRequest;
use Modules\Accounting\Http\Controllers\Admin\CustomerHistoryController;
use Modules\Accounting\Http\Controllers\Admin\ReportController;
use Modules\Accounting\Http\Controllers\Admin\ItemsGridController;
use Modules\Accounting\Http\Controllers\Admin\SalesQuotationController;
use Modules\Accounting\Http\Middleware\AuthorizeItemsGrid;
use Modules\Accounting\Support\ItemsGridDocuments;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::group([
        'prefix' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale(),
        'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath'],
    ], function () {
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle);
        });

        Route::prefix('admin/accounting')
            ->as('accounting.')
            ->group(function () {
                // صفحات Inertia (لوحة malbo).
                Route::prefix('accounts')->as('accounts.')->controller(AccountController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Account View List');
                    Route::get('/add', 'add')->name('add')->can('Account Create');
                    Route::post('/', 'store')->name('store')->can('Account Create');
                    Route::get('/{account}/edit', 'edit')->whereNumber('account')->name('edit')->can('Account Update');
                    Route::put('/{account}', 'update')->whereNumber('account')->name('update')->can('Account Update');
                    Route::delete('/{account}', 'destroy')->whereNumber('account')->name('destroy')->can('Account Delete');
                    Route::get('/{account}/info', 'info')->whereNumber('account')->name('info')->can('Account Info');
                });

                Route::prefix('journal-entries')->as('journal-entries.')->controller(JournalEntryController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Journal Entry View List');
                    Route::get('/add', 'add')->name('add')->can('Journal Entry Create');
                    Route::post('/', 'store')->name('store')->can('Journal Entry Create');
                    Route::get('/{journalEntry}', 'show')->whereNumber('journalEntry')->name('show')->can('Journal Entry View');
                    Route::get('/{journalEntry}/edit', 'edit')->whereNumber('journalEntry')->name('edit')->can('Journal Entry Update');
                    Route::put('/{journalEntry}', 'update')->whereNumber('journalEntry')->name('update')->can('Journal Entry Update');
                    Route::delete('/{journalEntry}', 'destroy')->whereNumber('journalEntry')->name('destroy')->can('Journal Entry Delete');
                    Route::post('/{journalEntry}/post', 'post')->whereNumber('journalEntry')->name('post')->can('Journal Entry Post');
                    Route::post('/{journalEntry}/void', 'void')->whereNumber('journalEntry')->name('void')->can('Journal Entry Void');
                    Route::get('/{journalEntry}/info', 'info')->whereNumber('journalEntry')->name('info')->can('Journal Entry Info');
                });

                Route::prefix('warehouses')->as('warehouses.')->controller(WarehouseController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Warehouse View List');
                    Route::get('/add', 'add')->name('add')->can('Warehouse Create');
                    Route::post('/', 'store')->name('store')->can('Warehouse Create');
                    Route::post('/branches', 'storeBranch')->name('branches.store')->can('Branch Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Warehouse Delete');
                    Route::get('/{warehouse}/edit', 'edit')->whereNumber('warehouse')->name('edit')->can('Warehouse Update');
                    Route::put('/{warehouse}', 'update')->whereNumber('warehouse')->name('update')->can('Warehouse Update');
                    Route::delete('/{warehouse}', 'destroy')->whereNumber('warehouse')->name('destroy')->can('Warehouse Delete');
                    Route::get('/{warehouse}/info', 'info')->whereNumber('warehouse')->name('info')->can('Warehouse Info');
                });

                Route::prefix('suppliers')->as('suppliers.')->controller(SupplierController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Supplier View List');
                    Route::get('/add', 'add')->name('add')->can('Supplier Create');
                    Route::post('/', 'store')->name('store')->can('Supplier Create');
                    Route::get('/{supplier}/edit', 'edit')->whereNumber('supplier')->name('edit')->can('Supplier Update');
                    Route::put('/{supplier}', 'update')->whereNumber('supplier')->name('update')->can('Supplier Update');
                });

                Route::prefix('receipt-vouchers')->as('receipt-vouchers.')->controller(ReceiptVoucherController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Receipt Voucher View List');
                    Route::get('/add', 'add')->name('add')->can('Receipt Voucher Create');
                    Route::post('/', 'store')->name('store')->can('Receipt Voucher Create');
                    Route::get('/parties', 'parties')->name('parties')->can('Receipt Voucher View List');
                    Route::get('/exchange-rate', 'exchangeRate')->name('exchange-rate')->can('Receipt Voucher View List');
                    Route::get('/{receiptVoucher}/edit', 'edit')->whereNumber('receiptVoucher')->name('edit')->can('Receipt Voucher Update');
                    Route::put('/{receiptVoucher}', 'update')->whereNumber('receiptVoucher')->name('update')->can('Receipt Voucher Update');
                    Route::post('/{receiptVoucher}/post', 'post')->whereNumber('receiptVoucher')->name('post')->can('Receipt Voucher Post');
                    Route::post('/{receiptVoucher}/cancel', 'cancel')->whereNumber('receiptVoucher')->name('cancel')->can('Receipt Voucher Cancel');
                    Route::delete('/{receiptVoucher}', 'destroy')->whereNumber('receiptVoucher')->name('destroy')->can('Receipt Voucher Delete');
                    Route::get('/{receiptVoucher}/info', 'info')->whereNumber('receiptVoucher')->name('info')->can('Receipt Voucher Info');
                    Route::get('/{receiptVoucher}/pdf', [AccountingPrintController::class, 'receiptVoucher'])->whereNumber('receiptVoucher')->name('pdf')->can('Receipt Voucher View');
                });

                Route::prefix('payment-vouchers')->as('payment-vouchers.')->controller(PaymentVoucherController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Payment Voucher View List');
                    Route::get('/add', 'add')->name('add')->can('Payment Voucher Create');
                    Route::post('/', 'store')->name('store')->can('Payment Voucher Create');
                    Route::get('/parties', 'parties')->name('parties')->can('Payment Voucher View List');
                    Route::get('/exchange-rate', 'exchangeRate')->name('exchange-rate')->can('Payment Voucher View List');
                    Route::get('/{paymentVoucher}/edit', 'edit')->whereNumber('paymentVoucher')->name('edit')->can('Payment Voucher Update');
                    Route::put('/{paymentVoucher}', 'update')->whereNumber('paymentVoucher')->name('update')->can('Payment Voucher Update');
                    Route::post('/{paymentVoucher}/post', 'post')->whereNumber('paymentVoucher')->name('post')->can('Payment Voucher Post');
                    Route::post('/{paymentVoucher}/cancel', 'cancel')->whereNumber('paymentVoucher')->name('cancel')->can('Payment Voucher Cancel');
                    Route::delete('/{paymentVoucher}', 'destroy')->whereNumber('paymentVoucher')->name('destroy')->can('Payment Voucher Delete');
                    Route::get('/{paymentVoucher}/info', 'info')->whereNumber('paymentVoucher')->name('info')->can('Payment Voucher Info');
                    Route::get('/{paymentVoucher}/pdf', [AccountingPrintController::class, 'paymentVoucher'])->whereNumber('paymentVoucher')->name('pdf')->can('Payment Voucher View');
                });

                Route::prefix('cheques')->as('cheques.')->controller(ChequeController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Cheque View List');
                    Route::post('/{cheque}/clear', 'clear')->whereNumber('cheque')->name('clear')->can('Cheque Clear');
                    Route::post('/{cheque}/bounce', 'bounce')->whereNumber('cheque')->name('bounce')->can('Cheque Bounce');
                    Route::post('/{cheque}/cancel', 'cancel')->whereNumber('cheque')->name('cancel')->can('Cheque Cancel');
                    Route::get('/{cheque}/info', 'info')->whereNumber('cheque')->name('info')->can('Cheque Info');
                });

                Route::prefix('fiscal-years')->as('fiscal-years.')->controller(FiscalYearController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Fiscal Year View List');
                    Route::post('/', 'store')->name('store')->can('Fiscal Year Create');
                    Route::put('/{fiscalYear}', 'update')->whereNumber('fiscalYear')->name('update')->can('Fiscal Year Update');
                    Route::post('/{fiscalYear}/close', 'close')->whereNumber('fiscalYear')->name('close')->can('Fiscal Year Close');
                    Route::post('/{fiscalYear}/reopen', 'reopen')->whereNumber('fiscalYear')->name('reopen')->can('Fiscal Year Reopen');
                    Route::get('/{fiscalYear}/info', 'info')->whereNumber('fiscalYear')->name('info')->can('Fiscal Year Info');
                    Route::get('/{fiscalYear}/periods', 'periods')->whereNumber('fiscalYear')->name('periods')->can('Fiscal Year View List');
                    Route::post('/periods/{period}/close', 'closePeriod')->whereNumber('period')->name('periods.close')->can('Accounting Period Close');
                    Route::post('/periods/{period}/reopen', 'reopenPeriod')->whereNumber('period')->name('periods.reopen')->can('Accounting Period Reopen');
                });

                Route::prefix('period-closing-vouchers')->as('period-closing-vouchers.')->controller(PeriodClosingVoucherController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Period Closing Voucher View List');
                    Route::post('/{periodClosingVoucher}/cancel', 'cancel')->whereNumber('periodClosingVoucher')->name('cancel')->can('Period Closing Voucher Cancel');
                    // المعلومات كانت متاحة لكل من يرى القائمة.
                    Route::get('/{periodClosingVoucher}/info', 'info')->whereNumber('periodClosingVoucher')->name('info')->can('Period Closing Voucher View List');
                });

                Route::prefix('payment-allocations')->as('payment-allocations.')->controller(PaymentAllocationController::class)->group(function () {
                    $sources = ['receipt-vouchers', 'payment-vouchers', 'sales-returns', 'purchase-returns'];
                    Route::post('/{sourceType}/{source}/allocate', 'allocate')->whereIn('sourceType', $sources)->whereNumber('source')->name('allocate')->can('Payment Allocation Create');
                    Route::post('/{sourceType}/{source}/auto', 'allocateAutomatically')->whereIn('sourceType', $sources)->whereNumber('source')->name('auto')->can('Payment Allocation Create');
                    Route::delete('/{allocation}', 'destroy')->whereNumber('allocation')->name('destroy')->can('Payment Allocation Delete');
                });

                Route::prefix('account-mappings')->as('account-mappings.')->controller(AccountMappingController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Accounting Mapping View List');
                    Route::put('/{mapping}', 'update')->whereNumber('mapping')->name('update')->can('Accounting Mapping Update');
                });

                Route::prefix('customer-groups')->as('customer-groups.')->controller(CustomerGroupController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Customer Group View List');
                    Route::get('/customers', 'customers')->name('customers')->can('Customer Group View List');
                    Route::post('/', 'store')->name('store')->can('Customer Group Create');
                    Route::put('/{group}', 'update')->whereNumber('group')->name('update')->can('Customer Group Update');
                    Route::put('/{group}/active', 'active')->whereNumber('group')->name('active')->can('Customer Group Update');
                    Route::delete('/{group}', 'destroy')->whereNumber('group')->name('destroy')->can('Customer Group Delete');
                });

                Route::prefix('taxes')->as('taxes.')->controller(TaxController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Tax View List');
                    Route::post('/', 'store')->name('store')->can('Tax Create');
                    Route::put('/{tax}', 'update')->whereNumber('tax')->name('update')->can('Tax Update');
                    Route::put('/{tax}/active', 'active')->whereNumber('tax')->name('active')->can('Tax Update');
                    Route::delete('/{tax}', 'destroy')->whereNumber('tax')->name('destroy')->can('Tax Delete');
                    Route::get('/{tax}/info', 'info')->whereNumber('tax')->name('info')->can('Tax Info');
                });

                Route::prefix('attachment-categories')->as('attachment-categories.')->controller(AttachmentCategoryController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Attachment Category View List');
                    Route::post('/', 'store')->name('store')->can('Attachment Category Create');
                    Route::put('/{category}', 'update')->whereNumber('category')->name('update')->can('Attachment Category Update');
                    Route::delete('/{category}', 'destroy')->whereNumber('category')->name('destroy')->can('Attachment Category Delete');
                });

                Route::prefix('clearance-attachment-types')->as('clearance-attachment-types.')->controller(ClearanceAttachmentTypeController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Clearance Attachment Type View List');
                    Route::post('/', 'store')->name('store')->can('Clearance Attachment Type Create');
                    Route::put('/{type}', 'update')->whereNumber('type')->name('update')->can('Clearance Attachment Type Update');
                    Route::delete('/{type}', 'destroy')->whereNumber('type')->name('destroy')->can('Clearance Attachment Type Delete');
                });

                Route::prefix('delivery-cost-elements')->as('delivery-cost-elements.')->controller(DeliveryCostElementController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Delivery Cost Element View List');
                    Route::post('/', 'store')->name('store')->can('Delivery Cost Element Create');
                    Route::put('/{element}', 'update')->whereNumber('element')->name('update')->can('Delivery Cost Element Update');
                    Route::delete('/{element}', 'destroy')->whereNumber('element')->name('destroy')->can('Delivery Cost Element Delete');
                });

                Route::prefix('additional-cost-types')->as('additional-cost-types.')->controller(AdditionalCostTypeController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Additional Cost Type View List');
                    Route::post('/', 'store')->name('store')->can('Additional Cost Type Create');
                    Route::put('/{type}', 'update')->whereNumber('type')->name('update')->can('Additional Cost Type Update');
                    Route::put('/{type}/active', 'active')->whereNumber('type')->name('active')->can('Additional Cost Type Update');
                    Route::delete('/{type}', 'destroy')->whereNumber('type')->name('destroy')->can('Additional Cost Type Delete');
                });

                Route::prefix('reappraisal-elements')->as('reappraisal-elements.')->controller(ReappraisalElementController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Reappraisal Element View List');
                    Route::post('/', 'store')->name('store')->can('Reappraisal Element Create');
                    Route::put('/{element}', 'update')->whereNumber('element')->name('update')->can('Reappraisal Element Update');
                    Route::put('/{element}/active', 'active')->whereNumber('element')->name('active')->can('Reappraisal Element Update');
                    Route::delete('/{element}', 'destroy')->whereNumber('element')->name('destroy')->can('Reappraisal Element Delete');
                });

                Route::prefix('currency-exchange-rates')->as('currency-exchange-rates.')->controller(CurrencyExchangeRateController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Currency Exchange Rate View List');
                    Route::post('/', 'store')->name('store')->can('Currency Exchange Rate Create');
                    Route::put('/{rate}', 'update')->whereNumber('rate')->name('update')->can('Currency Exchange Rate Update');
                    Route::delete('/{rate}', 'destroy')->whereNumber('rate')->name('destroy')->can('Currency Exchange Rate Delete');
                    Route::get('/{rate}/info', 'info')->whereNumber('rate')->name('info')->can('Currency Exchange Rate Info');
                });





                Route::prefix('purchase-quotations')->as('purchase-quotations.')->controller(PurchaseQuotationController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Purchase Quotation View List');
                    Route::get('/add', 'add')->name('add')->can('Purchase Quotation Create');
                    Route::post('/', 'store')->name('store')->can('Purchase Quotation Create');
                    Route::get('/orders', 'orders')->name('orders')->can('Purchase Quotation Create');
                    Route::get('/{purchaseQuotation}', 'edit')->whereNumber('purchaseQuotation')->name('edit')->can('Purchase Quotation View List');
                    Route::put('/{purchaseQuotation}', 'update')->whereNumber('purchaseQuotation')->name('update')->can('Purchase Quotation Update');
                    Route::post('/{purchaseQuotation}/approve', 'approve')->whereNumber('purchaseQuotation')->name('approve')->can('Purchase Quotation Approve');
                    Route::post('/{purchaseQuotation}/cancel', 'cancel')->whereNumber('purchaseQuotation')->name('cancel')->can('Purchase Quotation Cancel');
                    Route::post('/{purchaseQuotation}/invoice', 'invoice')->whereNumber('purchaseQuotation')->name('invoice')->can('Purchase Invoice Create');
                    Route::delete('/{purchaseQuotation}', 'destroy')->whereNumber('purchaseQuotation')->name('destroy')->can('Purchase Quotation Delete');
                    Route::get('/{purchaseQuotation}/info', 'info')->whereNumber('purchaseQuotation')->name('info')->can('Purchase Quotation View List');
                    Route::get('/{purchaseQuotation}/pdf', 'print')->whereNumber('purchaseQuotation')->name('pdf')->can('Purchase Quotation View List');
                });

                Route::prefix('purchase-orders')->as('purchase-orders.')->controller(PurchaseOrderController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Purchase Order View List');
                    Route::get('/add', 'add')->name('add')->can('Purchase Order Create');
                    Route::post('/', 'store')->name('store')->can('Purchase Order Create');
                    Route::get('/customers', 'customers')->name('customers')->can('Purchase Order View List');
                    Route::get('/sales-orders', 'salesOrders')->name('sales-orders')->can('Purchase Order View List');
                    Route::get('/{purchaseOrder}/edit', 'edit')->whereNumber('purchaseOrder')->name('edit')->can('Purchase Order View List');
                    Route::put('/{purchaseOrder}', 'update')->whereNumber('purchaseOrder')->name('update')->can('Purchase Order Update');
                    Route::post('/{purchaseOrder}/confirm', 'confirm')->whereNumber('purchaseOrder')->name('confirm')->can('Purchase Order Confirm');
                    Route::post('/{purchaseOrder}/receive', 'receive')->whereNumber('purchaseOrder')->name('receive')->can('Purchase Order Receive');
                    Route::post('/{purchaseOrder}/quotation', 'quotation')->whereNumber('purchaseOrder')->name('quotation')->can('Purchase Quotation Create');
                    Route::post('/{purchaseOrder}/invoice', 'invoice')->whereNumber('purchaseOrder')->name('invoice')->can('Purchase Invoice Create');
                    Route::post('/{purchaseOrder}/cancel', 'cancel')->whereNumber('purchaseOrder')->name('cancel')->can('Purchase Order Cancel');
                    Route::delete('/{purchaseOrder}', 'destroy')->whereNumber('purchaseOrder')->name('destroy')->can('Purchase Order Delete');
                    Route::get('/{purchaseOrder}/info', 'info')->whereNumber('purchaseOrder')->name('info')->can('Purchase Order Info');
                    Route::get('/{purchaseOrder}/pdf', 'print')->whereNumber('purchaseOrder')->name('pdf')->can('Purchase Order View');
                });

                Route::prefix('purchase-invoices')->as('purchase-invoices.')->controller(PurchaseInvoiceController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Purchase Invoice View List');
                    Route::get('/add', 'add')->name('add')->can('Purchase Invoice Create');
                    Route::post('/', 'store')->name('store')->can('Purchase Invoice Create');
                    Route::get('/orders', 'orders')->name('orders')->can('Purchase Invoice Create');
                    Route::get('/order-draft', 'orderDraft')->name('order-draft')->can('Purchase Invoice Create');
                    Route::get('/{purchaseInvoice}/edit', 'edit')->whereNumber('purchaseInvoice')->name('edit')->can('Purchase Invoice View List');
                    Route::put('/{purchaseInvoice}', 'update')->whereNumber('purchaseInvoice')->name('update')->can('Purchase Invoice Update');
                    Route::post('/{purchaseInvoice}/post', 'post')->whereNumber('purchaseInvoice')->name('post')->can('Purchase Invoice Post');
                    Route::post('/{purchaseInvoice}/cancel', 'cancel')->whereNumber('purchaseInvoice')->name('cancel')->can('Purchase Invoice Cancel');
                    Route::delete('/{purchaseInvoice}', 'destroy')->whereNumber('purchaseInvoice')->name('destroy')->can('Purchase Invoice Delete');
                    Route::get('/{purchaseInvoice}/info', 'info')->whereNumber('purchaseInvoice')->name('info')->can('Purchase Invoice Info');
                    Route::get('/{purchaseInvoice}/pdf', 'print')->whereNumber('purchaseInvoice')->name('pdf')->can('Purchase Invoice View');
                });

                Route::prefix('purchase-returns')->as('purchase-returns.')->controller(PurchaseReturnController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Purchase Return View List');
                    Route::get('/add', 'add')->name('add')->can('Purchase Return Create');
                    Route::post('/', 'store')->name('store')->can('Purchase Return Create');
                    Route::get('/invoices', 'invoices')->name('invoices')->can('Purchase Return Create');
                    Route::get('/invoice-draft', 'invoiceDraft')->name('invoice-draft')->can('Purchase Return Create');
                    Route::get('/{purchaseReturn}/edit', 'edit')->whereNumber('purchaseReturn')->name('edit')->can('Purchase Return Update');
                    Route::put('/{purchaseReturn}', 'update')->whereNumber('purchaseReturn')->name('update')->can('Purchase Return Update');
                    Route::post('/{purchaseReturn}/post', 'post')->whereNumber('purchaseReturn')->name('post')->can('Purchase Return Post');
                    Route::post('/{purchaseReturn}/cancel', 'cancel')->whereNumber('purchaseReturn')->name('cancel')->can('Purchase Return Cancel');
                    Route::delete('/{purchaseReturn}', 'destroy')->whereNumber('purchaseReturn')->name('destroy')->can('Purchase Return Delete');
                    Route::get('/{purchaseReturn}/info', 'info')->whereNumber('purchaseReturn')->name('info')->can('Purchase Return Info');
                    Route::get('/{purchaseReturn}/pdf', 'print')->whereNumber('purchaseReturn')->name('pdf')->can('Purchase Return View');
                });


                Route::prefix('consolidated-purchase-orders')->as('consolidated-purchase-orders.')->group(function () {
                    Route::controller(ConsolidatedPurchaseOrderController::class)->group(function () {
                        Route::get('/', 'index')->name('index')->can('Consolidated Purchase Order View List');
                        Route::get('/add', 'add')->name('add')->can('Consolidated Purchase Order Create');
                        Route::post('/', 'store')->name('store')->can('Consolidated Purchase Order Create');
                        Route::middleware(AuthorizeAnyPermission::class.':Consolidated Purchase Order Create,Consolidated Purchase Order Update')->group(function () {
                            Route::get('/picker/purchase-orders', 'purchaseOrders')->name('picker.purchase-orders');
                            Route::post('/picker/items', 'purchaseOrderItems')->name('picker.items');
                            Route::post('/picker/rows', 'pullRows')->name('picker.rows');
                            Route::get('/parties', 'parties')->name('parties');
                            Route::post('/parties', 'storeParty')->name('parties.store');
                            Route::post('/lookups', 'storeLookup')->name('lookups.store');
                        });
                        Route::get('/{consolidatedPurchaseOrder}/edit', 'edit')->whereNumber('consolidatedPurchaseOrder')->name('edit')->can('Consolidated Purchase Order Update');
                        Route::put('/{consolidatedPurchaseOrder}', 'update')->whereNumber('consolidatedPurchaseOrder')->name('update')->can('Consolidated Purchase Order Update');
                        Route::post('/{consolidatedPurchaseOrder}/status', 'changeStatus')->whereNumber('consolidatedPurchaseOrder')->name('status')->can('Consolidated Purchase Order Update');
                        Route::delete('/{consolidatedPurchaseOrder}', 'destroy')->whereNumber('consolidatedPurchaseOrder')->name('destroy')->can('Consolidated Purchase Order Delete');
                        Route::get('/{consolidatedPurchaseOrder}/quote-request/{supplier}', 'quoteRequest')->whereNumber(['consolidatedPurchaseOrder', 'supplier'])->name('quote-request')->can('Consolidated Purchase Order View');
                        Route::post('/{consolidatedPurchaseOrder}/items/pull', 'pullItems')->whereNumber('consolidatedPurchaseOrder')->name('items.pull')->can('Consolidated Purchase Order Update');
                        Route::put('/{consolidatedPurchaseOrder}/items/{item}', 'updateItem')->whereNumber(['consolidatedPurchaseOrder', 'item'])->name('items.update')->can('Consolidated Purchase Order Update');
                        Route::delete('/{consolidatedPurchaseOrder}/items/{item}', 'destroyItem')->whereNumber(['consolidatedPurchaseOrder', 'item'])->name('items.destroy')->can('Consolidated Purchase Order Update');
                    });
                    Route::controller(ConsolidatedPurchaseOrderTabController::class)->group(function () {
                        Route::post('/{consolidatedPurchaseOrder}/price-offers/{offer}/award', 'award')->whereNumber(['consolidatedPurchaseOrder', 'offer'])->name('award')->can('Consolidated Purchase Order Update');
                        Route::delete('/{consolidatedPurchaseOrder}/award', 'cancelAward')->whereNumber('consolidatedPurchaseOrder')->name('award.cancel')->can('Consolidated Purchase Order Update');
                        Route::post('/{consolidatedPurchaseOrder}/{tab}', 'store')->whereNumber('consolidatedPurchaseOrder')->whereIn('tab', ConsolidatedPurchaseOrderTabRequest::TABS)->name('tabs.store')->can('Consolidated Purchase Order Update');
                        Route::put('/{consolidatedPurchaseOrder}/{tab}/{record}', 'update')->whereNumber(['consolidatedPurchaseOrder', 'record'])->whereIn('tab', ConsolidatedPurchaseOrderTabRequest::TABS)->name('tabs.update')->can('Consolidated Purchase Order Update');
                        Route::delete('/{consolidatedPurchaseOrder}/{tab}/{record}', 'destroy')->whereNumber(['consolidatedPurchaseOrder', 'record'])->whereIn('tab', ConsolidatedPurchaseOrderTabRequest::TABS)->name('tabs.destroy')->can('Consolidated Purchase Order Update');
                    });
                });



                // شبكة الاصناف المشتركة لكل المستندات. الوسيط يتحقق من صلاحية انشاء/تعديل المستند (ItemsGridDocuments).
                Route::prefix('items-grid/{document}')->as('items-grid.')->controller(ItemsGridController::class)
                    ->whereIn('document', ItemsGridDocuments::keys())
                    ->middleware(AuthorizeItemsGrid::class)
                    ->group(function () {
                        Route::get('/products', 'products')->name('products');
                        Route::get('/categories', 'categories')->name('categories');
                        Route::get('/piece-outputs', 'pieceOutputs')->name('piece-outputs');
                        Route::get('/item', 'item')->name('item');
                        Route::get('/barcode', 'barcode')->name('barcode');
                        Route::get('/match-pieces', 'matchPieces')->name('match-pieces');
                        Route::get('/stock', 'stock')->name('stock');
                        Route::get('/services', 'services')->name('services');
                        Route::get('/goods', 'goods')->name('goods');
                        Route::get('/service', 'service')->name('service');
                        Route::get('/service-providers', 'serviceProviders')->name('service-providers');
                        Route::post('/service-providers', 'storeServiceProvider')->name('service-providers.store');
                        Route::get('/prices', 'prices')->name('prices');
                        Route::post('/totals', 'totals')->name('totals');
                        Route::get('/parties', 'parties')->name('parties');
                        Route::get('/party', 'party')->name('party');
                        Route::get('/exchange-rate', 'exchangeRate')->name('exchange-rate');
                    });

                Route::prefix('sales-quotations')->as('sales-quotations.')->controller(SalesQuotationController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Sales Quotation View List');
                    Route::get('/add', 'add')->name('add')->can('Sales Quotation Create');
                    Route::post('/', 'store')->name('store')->can('Sales Quotation Create');
                    Route::get('/{salesQuotation}/edit', 'edit')->whereNumber('salesQuotation')->name('edit')->can('Sales Quotation Update');
                    Route::put('/{salesQuotation}', 'update')->whereNumber('salesQuotation')->name('update')->can('Sales Quotation Update');
                    Route::post('/{salesQuotation}/approve', 'approve')->whereNumber('salesQuotation')->name('approve')->can('Sales Quotation Approve');
                    Route::post('/{salesQuotation}/convert', 'convert')->whereNumber('salesQuotation')->name('convert')->can('Sales Order Create');
                    Route::post('/{salesQuotation}/cancel', 'cancel')->whereNumber('salesQuotation')->name('cancel')->can('Sales Quotation Cancel');
                    Route::delete('/{salesQuotation}', 'destroy')->whereNumber('salesQuotation')->name('destroy')->can('Sales Quotation Delete');
                    Route::get('/{salesQuotation}/info', 'info')->whereNumber('salesQuotation')->name('info')->can('Sales Quotation Info');
                    // مصفوفة لا نص: alias الحزمة PDF يجعل 'pdf' اسم صنف فيُكسر تحميل المسارات.
                    Route::get('/{salesQuotation}/pdf', [SalesQuotationController::class, 'pdf'])->whereNumber('salesQuotation')->name('pdf')->can('Sales Quotation View');
                });

                Route::prefix('production-orders')->as('production-orders.')->controller(ProductionOrderController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Production Order View List');
                    Route::get('/add', 'add')->name('add')->can('Production Order Create');
                    Route::post('/', 'store')->name('store')->can('Production Order Create');
                    Route::middleware(AuthorizeAnyPermission::class.':Production Order Create,Production Order Update')->group(function () {
                        Route::post('/cost-preview', 'costPreview')->name('cost-preview');
                        Route::get('/sales-orders', 'salesOrders')->name('sales-orders');
                        Route::get('/sales-order', 'salesOrder')->name('sales-order');
                        Route::get('/materials', 'materials')->name('materials');
                        Route::get('/material', 'material')->name('material');
                        Route::get('/services', 'services')->name('services');
                        Route::post('/service', 'service')->name('service');
                        Route::get('/workers', 'workers')->name('workers');
                        Route::get('/providers', 'providers')->name('providers');
                        Route::post('/providers', 'storeProvider')->name('providers.store');
                    });
                    Route::get('/{productionOrder}/edit', 'edit')->whereNumber('productionOrder')->name('edit')->can('Production Order Update');
                    Route::put('/{productionOrder}', 'update')->whereNumber('productionOrder')->name('update')->can('Production Order Update');
                    Route::post('/{productionOrder}/start', 'start')->whereNumber('productionOrder')->name('start')->can('Production Order Start');
                    Route::post('/{productionOrder}/complete', 'complete')->whereNumber('productionOrder')->name('complete')->can('Production Order Complete');
                    Route::post('/{productionOrder}/reopen', 'reopen')->whereNumber('productionOrder')->name('reopen')->can('Production Order Complete');
                    Route::post('/{productionOrder}/cancel', 'cancel')->whereNumber('productionOrder')->name('cancel')->can('Production Order Cancel');
                    Route::delete('/{productionOrder}', 'destroy')->whereNumber('productionOrder')->name('destroy')->can('Production Order Delete');
                });

                Route::prefix('sales-orders')->as('sales-orders.')->controller(SalesOrderController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Sales Order View List');
                    Route::get('/add', 'add')->name('add')->can('Sales Order Create');
                    Route::post('/', 'store')->name('store')->can('Sales Order Create');
                    Route::get('/suppliers', 'suppliers')->name('suppliers')->can('Sales Order View List');
                    Route::get('/quotations', 'quotations')->name('quotations')->can('Sales Order Create');
                    Route::get('/quotation-defaults', 'quotationDefaults')->name('quotation-defaults')->can('Sales Order Create');
                    Route::get('/{salesOrder}/edit', 'edit')->whereNumber('salesOrder')->name('edit')->can('Sales Order Update');
                    Route::put('/{salesOrder}', 'update')->whereNumber('salesOrder')->name('update')->can('Sales Order Update');
                    Route::post('/{salesOrder}/confirm', 'confirm')->whereNumber('salesOrder')->name('confirm')->can('Sales Order Confirm');
                    Route::post('/{salesOrder}/prepare', 'prepare')->whereNumber('salesOrder')->name('prepare')->can('Sales Order Prepare');
                    Route::post('/{salesOrder}/invoice', 'invoice')->whereNumber('salesOrder')->name('invoice')->can('Sales Invoice Create');
                    Route::post('/{salesOrder}/cancel', 'cancel')->whereNumber('salesOrder')->name('cancel')->can('Sales Order Cancel');
                    Route::delete('/{salesOrder}', 'destroy')->whereNumber('salesOrder')->name('destroy')->can('Sales Order Delete');
                    Route::get('/{salesOrder}/info', 'info')->whereNumber('salesOrder')->name('info')->can('Sales Order Info');
                    Route::get('/{salesOrder}/pdf', [SalesOrderController::class, 'pdf'])->whereNumber('salesOrder')->name('pdf')->can('Sales Order View');
                });

                Route::prefix('sales-invoices')->as('sales-invoices.')->controller(SalesInvoiceController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Sales Invoice View List');
                    Route::get('/add', 'add')->name('add')->can('Sales Invoice Create');
                    Route::post('/', 'store')->name('store')->can('Sales Invoice Create');
                    Route::get('/orders', 'orders')->name('orders')->can('Sales Invoice Create');
                    Route::get('/order-defaults', 'orderDefaults')->name('order-defaults')->can('Sales Invoice Create');
                    Route::get('/{salesInvoice}/edit', 'edit')->whereNumber('salesInvoice')->name('edit')->can('Sales Invoice View List');
                    Route::put('/{salesInvoice}', 'update')->whereNumber('salesInvoice')->name('update')->can('Sales Invoice Update');
                    Route::post('/{salesInvoice}/post', 'post')->whereNumber('salesInvoice')->name('post')->can('Sales Invoice Post');
                    Route::post('/{salesInvoice}/cancel', 'cancel')->whereNumber('salesInvoice')->name('cancel')->can('Sales Invoice Cancel');
                    Route::delete('/{salesInvoice}', 'destroy')->whereNumber('salesInvoice')->name('destroy')->can('Sales Invoice Delete');
                    Route::get('/{salesInvoice}/info', 'info')->whereNumber('salesInvoice')->name('info')->can('Sales Invoice Info');
                    Route::get('/{salesInvoice}/pdf', [SalesInvoiceController::class, 'pdf'])->whereNumber('salesInvoice')->name('pdf')->can('Sales Invoice View');
                });

                Route::prefix('sales-returns')->as('sales-returns.')->controller(SalesReturnController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Sales Return View List');
                    Route::get('/add', 'add')->name('add')->can('Sales Return Create');
                    Route::post('/', 'store')->name('store')->can('Sales Return Create');
                    Route::get('/invoices', 'invoices')->name('invoices')->can('Sales Return Create');
                    Route::get('/invoice-defaults', 'invoiceDefaults')->name('invoice-defaults')->can('Sales Return Create');
                    Route::get('/{salesReturn}/edit', 'edit')->whereNumber('salesReturn')->name('edit')->can('Sales Return Update');
                    Route::put('/{salesReturn}', 'update')->whereNumber('salesReturn')->name('update')->can('Sales Return Update');
                    Route::post('/{salesReturn}/post', 'post')->whereNumber('salesReturn')->name('post')->can('Sales Return Post');
                    Route::post('/{salesReturn}/cancel', 'cancel')->whereNumber('salesReturn')->name('cancel')->can('Sales Return Cancel');
                    Route::delete('/{salesReturn}', 'destroy')->whereNumber('salesReturn')->name('destroy')->can('Sales Return Delete');
                    Route::get('/{salesReturn}/info', 'info')->whereNumber('salesReturn')->name('info')->can('Sales Return Info');
                    Route::get('/{salesReturn}/pdf', [AccountingPrintController::class, 'salesReturn'])->name('pdf')->can('Sales Return View');
                });
















                Route::prefix('reports')->as('reports.')->group(function () {
                    Route::controller(ReportController::class)->group(function () {
                        Route::get('/trial-balance', 'trialBalance')->name('trial-balance')->can('Accounting Report View List');
                        Route::get('/trial-balance/export', 'trialBalanceExport')->name('trial-balance.export')->can('Accounting Report View List');
                        Route::get('/ledger', 'ledger')->name('ledger')->can('Accounting Report View List');
                        Route::get('/ledger/export', 'ledgerExport')->name('ledger.export')->can('Accounting Report View List');
                        Route::get('/ledger/accounts', 'ledgerAccounts')->name('ledger.accounts')->can('Accounting Report View List');
                        Route::get('/balance-sheet', 'balanceSheet')->name('balance-sheet')->can('Accounting Report View List');
                        Route::get('/balance-sheet/export', 'balanceSheetExport')->name('balance-sheet.export')->can('Accounting Report View List');
                        Route::get('/income-statement', 'incomeStatement')->name('income-statement')->can('Accounting Report View List');
                        Route::get('/income-statement/export', 'incomeStatementExport')->name('income-statement.export')->can('Accounting Report View List');
                        Route::get('/receivables-aging', 'aging')->defaults('kind', 'receivable')->name('receivable-aging')->can('Accounting Report View List');
                        Route::get('/receivables-aging/export', 'agingExport')->defaults('kind', 'receivable')->name('receivable-aging.export')->can('Accounting Report View List');
                        Route::get('/payables-aging', 'aging')->defaults('kind', 'payable')->name('payable-aging')->can('Accounting Report View List');
                        Route::get('/payables-aging/export', 'agingExport')->defaults('kind', 'payable')->name('payable-aging.export')->can('Accounting Report View List');
                    });

                    Route::controller(CustomerHistoryController::class)->group(function () {
                        Route::get('/customer-history', 'index')->name('customer-history')->can('Customer History View');
                        Route::get('/customer-history/clients', 'clients')->name('customer-history.clients')->can('Customer History View');
                    });
                });
            });
    });
});
