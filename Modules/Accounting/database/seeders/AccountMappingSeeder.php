<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;

class AccountMappingSeeder extends Seeder
{
    public function run(): void
    {
        $mappings = [
            ['source_module' => 'Items', 'source_key' => 'cash', 'account_code' => '1110'],
            ['source_module' => 'Items', 'source_key' => 'bank', 'account_code' => '1120'],
            ['source_module' => 'Items', 'source_key' => 'accounts_receivable', 'account_code' => '1130'],
            ['source_module' => 'Items', 'source_key' => 'inventory', 'account_code' => '1140'],
            ['source_module' => 'Items', 'source_key' => 'accounts_payable', 'account_code' => '2110'],
            ['source_module' => 'Items', 'source_key' => 'sales_revenue', 'account_code' => '4100'],
            ['source_module' => 'Items', 'source_key' => 'cost_of_goods_sold', 'account_code' => '5100'],
            ['source_module' => 'Accounting', 'source_key' => 'purchase_invoice_purchases', 'account_code' => '5110'],
            ['source_module' => 'Accounting', 'source_key' => 'purchase_invoice_tax', 'account_code' => '1180'],
            ['source_module' => 'Accounting', 'source_key' => 'purchase_invoice_payable', 'account_code' => '2110'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_receivable', 'account_code' => '1130'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_revenue', 'account_code' => '4100'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_tax', 'account_code' => '2140'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_service_revenue', 'account_code' => '4110'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_transport_revenue', 'account_code' => '4150'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_service_cost', 'account_code' => '5160'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_transport_cost', 'account_code' => '5320'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_service_cost_applied', 'account_code' => '5170'],
            ['source_module' => 'Accounting', 'source_key' => 'sales_invoice_service_cost_accrued', 'account_code' => '2120'],
            ['source_module' => 'Core', 'source_key' => 'user_account_parent', 'account_code' => '1130'],
            ['source_module' => 'Core', 'source_key' => 'supplier_account_parent', 'account_code' => '2110'],
            ['source_module' => 'Core', 'source_key' => 'customer_account_parent', 'account_code' => '1130'],
        ];

        foreach ($mappings as $mapping) {
            $account = Account::query()->where('code', $mapping['account_code'])->first();

            AccountMapping::updateOrCreate(
                [
                    'source_module' => $mapping['source_module'],
                    'source_key' => $mapping['source_key'],
                ],
                [
                    'account_id' => $account?->id,
                    'created_by' => null,
                ]
            );
        }
    }
}
