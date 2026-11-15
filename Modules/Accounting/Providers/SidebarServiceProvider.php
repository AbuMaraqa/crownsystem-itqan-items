<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\SidebarGroup;
use Modules\Core\Services\SidebarItem;
use Modules\Core\Services\SidebarService;

class SidebarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $sidebar = $this->app->make(SidebarService::class);

        $sidebar->add(
            (new SidebarGroup('Accounting', 'solar-calculator-bold-duotone', [], 80))
                ->add(new SidebarItem('Chart of Accounts', 'solar-hierarchy-3-bold-duotone', ['Account View List'], 'accounting.accounts.index'))
                ->add(new SidebarItem('Journal Entries', 'solar-document-add-bold-duotone', ['Journal Entry View List'], 'accounting.journal-entries.index'))
                ->add(new SidebarItem('Suppliers', 'solar-users-group-rounded-bold-duotone', ['Supplier View List'], 'accounting.suppliers.index'))
                ->add(new SidebarItem('Purchase Orders', 'solar-cart-large-bold-duotone', ['Purchase Order View List'], 'accounting.purchase-orders.index'))
                ->add(new SidebarItem('Purchase Quotations', 'solar-tag-price-bold-duotone', ['Purchase Quotation View List'], 'accounting.purchase-quotations.index'))
                ->add(new SidebarItem('Purchase Invoices', 'solar-clipboard-list-bold-duotone', ['Purchase Invoice View List'], 'accounting.purchase-invoices.index'))
                ->add(new SidebarItem('Purchase Returns', 'solar-refresh-square-bold-duotone', ['Purchase Return View List'], 'accounting.purchase-returns.index'))
                ->add(new SidebarItem('Customer Groups', 'solar-users-group-two-rounded-bold-duotone', ['Customer Group View List'], 'accounting.customer-groups.index'))
                ->add(new SidebarItem('Sales Quotations', 'solar-document-text-bold-duotone', ['Sales Quotation View List'], 'accounting.sales-quotations.index'))
                ->add(new SidebarItem('Sales Orders', 'solar-cart-large-bold-duotone', ['Sales Order View List'], 'accounting.sales-orders.index'))
                ->add(new SidebarItem('Sales Invoices', 'solar-clipboard-list-bold-duotone', ['Sales Invoice View List'], 'accounting.sales-invoices.index'))
                ->add(new SidebarItem('Sales Returns', 'solar-refresh-square-bold-duotone', ['Sales Return View List'], 'accounting.sales-returns.index'))
                ->add(new SidebarItem('Receipt Vouchers', 'solar-inbox-in-bold-duotone', ['Receipt Voucher View List'], 'accounting.receipt-vouchers.index'))
                ->add(new SidebarItem('Payment Vouchers', 'solar-inbox-out-bold-duotone', ['Payment Voucher View List'], 'accounting.payment-vouchers.index'))
                ->add(new SidebarItem('Cheques', 'solar-document-add-bold-duotone', ['Cheque View List'], 'accounting.cheques.index'))
                ->add(new SidebarItem('Fiscal Years', 'solar-calendar-bold-duotone', ['Fiscal Year View List'], 'accounting.fiscal-years.index'))
                ->add(new SidebarItem('Account Mappings', 'solar-link-bold-duotone', ['Accounting Mapping View List'], 'accounting.account-mappings.index'))
                ->add(new SidebarItem('Taxes', 'solar-clipboard-list-bold-duotone', ['Tax View List'], 'accounting.taxes.index'))
                ->add(new SidebarItem('Exchange Rates', 'solar-wallet-money-bold-duotone', ['Currency Exchange Rate View List'], 'accounting.currency-exchange-rates.index'))
                ->add(new SidebarItem('Trial Balance', 'solar-chart-square-bold-duotone', ['Accounting Report View List'], 'accounting.reports.trial-balance'))
                ->add(new SidebarItem('Ledger', 'solar-book-2-bold-duotone', ['Accounting Report View List'], 'accounting.reports.ledger'))
                ->add(new SidebarItem('Customer History', 'solar-user-id-bold-duotone', ['Customer History View'], 'accounting.reports.customer-history'))
                ->add(new SidebarItem('Balance Sheet', 'solar-clipboard-list-bold-duotone', ['Accounting Report View List'], 'accounting.reports.balance-sheet'))
                ->add(new SidebarItem('Income Statement', 'solar-graph-new-bold-duotone', ['Accounting Report View List'], 'accounting.reports.income-statement'))
                ->add(new SidebarItem('Receivables Aging', 'solar-hourglass-bold-duotone', ['Accounting Report View List'], 'accounting.reports.receivable-aging'))
                ->add(new SidebarItem('Payables Aging', 'solar-hourglass-line-bold-duotone', ['Accounting Report View List'], 'accounting.reports.payable-aging'))
                ->add(new SidebarItem('Period Closing Vouchers', 'solar-lock-keyhole-bold-duotone', ['Period Closing Voucher View List'], 'accounting.period-closing-vouchers.index'))
        );

        $sidebar->add(
            (new SidebarGroup('Production', 'solar-settings-minimalistic-bold-duotone', [], 82))
                ->add(new SidebarItem('Production Orders', 'solar-sledgehammer-bold-duotone', ['Production Order View List'], 'accounting.production-orders.index'))
        );

        $sidebar->add(
            (new SidebarGroup('Import','solar-widget-add-bold-duotone', [], 85))
                ->add(new SidebarItem('Consolidated Purchase Orders', 'solar-widget-add-bold-duotone', ['Consolidated Purchase Order View List'], 'accounting.consolidated-purchase-orders.index'))
        );
    }

    public function provides(): array
    {
        return [];
    }
}
