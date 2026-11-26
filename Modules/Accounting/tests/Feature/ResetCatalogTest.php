<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * تفريغ الكتالوج يوم الانتقال: بلا --execute لا يُحذف شيء؛ ومعه تذهب الاصناف والتصنيفات والمستندات
 * التجريبية ودفتر المخزون وقيود المستندات، ويبقى المستخدمون والحسابات.
 */
class ResetCatalogTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
    }

    public function test_the_report_deletes_nothing_and_execute_empties_the_catalogue_and_its_documents(): void
    {
        $this->postedInvoice();
        $users = User::query()->count();
        $accounts = Account::query()->count();
        $products = Product::query()->count();

        $this->assertGreaterThan(0, StockMovement::query()->count());
        $this->assertGreaterThan(0, JournalEntry::query()->where('source_type', 'SalesInvoice')->count());

        $this->artisan('items:reset-catalog')->assertSuccessful();
        $this->assertSame($products, Product::query()->count());

        $this->artisan('items:reset-catalog', ['--execute' => true])->assertSuccessful();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, SalesInvoice::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'SalesInvoice')->count());
        $this->assertSame($users, User::query()->count());
        $this->assertSame($accounts, Account::query()->count());
    }
}
