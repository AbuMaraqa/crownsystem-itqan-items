<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\database\seeders\AccountingDatabaseSeeder;
use Tests\TestCase;

class AccountingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_seeders_are_idempotent(): void
    {
        $this->seed(AccountingDatabaseSeeder::class);
        $this->seed(AccountingDatabaseSeeder::class);

        $this->assertSame(1, Account::query()->where('code', '1000')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Items')->where('source_key', 'sales_revenue')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Accounting')->where('source_key', 'purchase_invoice_purchases')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Accounting')->where('source_key', 'purchase_invoice_payable')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Accounting')->where('source_key', 'sales_invoice_receivable')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Accounting')->where('source_key', 'sales_invoice_revenue')->count());
        $this->assertSame(1, AccountMapping::query()->where('source_module', 'Accounting')->where('source_key', 'sales_invoice_tax')->count());
    }
}
