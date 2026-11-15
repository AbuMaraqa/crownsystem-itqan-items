<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Support\InventoryPermissions;
use Modules\Accounting\Support\LedgerPermissions;
use Modules\Accounting\Support\PurchasingPermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'Account Management' => [
                'Account View List',
                'Account Create',
                'Account Update',
                'Account Delete',
                'Account View',
                'Account Info',
            ],
            'Journal Entry Management' => [
                'Journal Entry View List',
                'Journal Entry Create',
                'Journal Entry Update',
                'Journal Entry Delete',
                'Journal Entry View',
                'Journal Entry Info',
                'Journal Entry Post',
                'Journal Entry Void',
            ],
            'Warehouse Management' => [
                'Warehouse View List',
                'Warehouse Create',
                'Warehouse Update',
                'Warehouse Delete',
                'Warehouse View',
                'Warehouse Info',
            ],
            'Supplier Management' => [
                'Supplier View List',
                'Supplier Create',
                'Supplier Update',
                'Supplier Delete',
                'Supplier View',
            ],
            'Purchase Order Management' => [
                'Purchase Order View List',
                'Purchase Order Create',
                'Purchase Order Update',
                'Purchase Order Delete',
                'Purchase Order View',
                'Purchase Order Info',
                'Purchase Order Confirm',
                'Purchase Order Receive',
                'Purchase Order Invoice',
                'Purchase Order Cancel',
            ],
            'Consolidated Purchase Order Management' => [
                'Consolidated Purchase Order View List',
                'Consolidated Purchase Order Create',
                'Consolidated Purchase Order Update',
                'Consolidated Purchase Order Delete',
                'Consolidated Purchase Order View',
            ],
            'Purchase Invoice Management' => [
                'Purchase Invoice View List',
                'Purchase Invoice Create',
                'Purchase Invoice Update',
                'Purchase Invoice Delete',
                'Purchase Invoice View',
                'Purchase Invoice Info',
                'Purchase Invoice Post',
            ],
            'Purchase Return Management' => [
                'Purchase Return View List',
                'Purchase Return Create',
                'Purchase Return Update',
                'Purchase Return Delete',
                'Purchase Return View',
                'Purchase Return Info',
                'Purchase Return Post',
            ],
            'Sales Quotation Management' => [
                'Sales Quotation View List',
                'Sales Quotation Create',
                'Sales Quotation Update',
                'Sales Quotation Delete',
                'Sales Quotation View',
                'Sales Quotation Info',
                'Sales Quotation Approve',
                'Sales Quotation Cancel',
            ],
            'Sales Order Management' => [
                'Sales Order View List',
                'Sales Order Create',
                'Sales Order Update',
                'Sales Order Delete',
                'Sales Order View',
                'Sales Order Info',
                'Sales Order Confirm',
                'Sales Order Prepare',
                'Sales Order Invoice',
                'Sales Order Cancel',
            ],
            'Production Order Management' => [
                'Production Order View List',
                'Production Order Create',
                'Production Order Update',
                'Production Order Delete',
                'Production Order View',
                'Production Order Start',
                'Production Order Complete',
                'Production Order Cancel',
            ],
            'Sales Invoice Management' => [
                'Sales Invoice View List',
                'Sales Invoice Create',
                'Sales Invoice Update',
                'Sales Invoice Delete',
                'Sales Invoice View',
                'Sales Invoice Info',
                'Sales Invoice Post',
            ],
            'Sales Return Management' => [
                'Sales Return View List',
                'Sales Return Create',
                'Sales Return Update',
                'Sales Return Delete',
                'Sales Return View',
                'Sales Return Info',
                'Sales Return Post',
            ],
            'Receipt Voucher Management' => [
                'Receipt Voucher View List',
                'Receipt Voucher Create',
                'Receipt Voucher Update',
                'Receipt Voucher Delete',
                'Receipt Voucher View',
                'Receipt Voucher Info',
                'Receipt Voucher Post',
            ],
            'Payment Voucher Management' => [
                'Payment Voucher View List',
                'Payment Voucher Create',
                'Payment Voucher Update',
                'Payment Voucher Delete',
                'Payment Voucher View',
                'Payment Voucher Info',
                'Payment Voucher Post',
            ],
            'Cheque Management' => [
                'Cheque View List',
                'Cheque View',
                'Cheque Info',
                'Cheque Clear',
                'Cheque Bounce',
                'Cheque Cancel',
            ],
            'Tax Management' => [
                'Tax View List',
                'Tax Create',
                'Tax Update',
                'Tax Delete',
                'Tax View',
                'Tax Info',
            ],
            'Attachment Category Management' => [
                'Attachment Category View List',
                'Attachment Category Create',
                'Attachment Category Update',
                'Attachment Category Delete',
            ],
            'Clearance Attachment Type Management' => [
                'Clearance Attachment Type View List',
                'Clearance Attachment Type Create',
                'Clearance Attachment Type Update',
                'Clearance Attachment Type Delete',
            ],
            'Delivery Cost Element Management' => [
                'Delivery Cost Element View List',
                'Delivery Cost Element Create',
                'Delivery Cost Element Update',
                'Delivery Cost Element Delete',
            ],
            'Customer Group Management' => [
                'Customer Group View List',
                'Customer Group Create',
                'Customer Group Update',
                'Customer Group Delete',
            ],
            'Additional Cost Type Management' => [
                'Additional Cost Type View List',
                'Additional Cost Type Create',
                'Additional Cost Type Update',
                'Additional Cost Type Delete',
            ],
            'Reappraisal Element Management' => [
                'Reappraisal Element View List',
                'Reappraisal Element Create',
                'Reappraisal Element Update',
                'Reappraisal Element Delete',
            ],
            'Currency Exchange Rate Management' => [
                'Currency Exchange Rate View List',
                'Currency Exchange Rate Create',
                'Currency Exchange Rate Update',
                'Currency Exchange Rate Delete',
                'Currency Exchange Rate View',
                'Currency Exchange Rate Info',
            ],
            'Fiscal Year Management' => [
                'Fiscal Year View List',
                'Fiscal Year Create',
                'Fiscal Year Update',
                'Fiscal Year View',
                'Fiscal Year Info',
                'Fiscal Year Close',
                'Fiscal Year Reopen',
            ],
            'Accounting Mapping Management' => [
                'Accounting Mapping View List',
                'Accounting Mapping Update',
            ],
            'Accounting Report Management' => [
                'Accounting Report View List',
                'Accounting Report Export',
                'Customer History View',
            ],
        ];

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        foreach ($permissions as $moduleName => $names) {
            foreach ($names as $name) {
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['module_name' => $moduleName]
                );

                $role->givePermissionTo($name);
            }
        }

        InventoryPermissions::sync();
        LedgerPermissions::sync();
        PurchasingPermissions::sync();
    }
}
