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
        // رابط اتقان لتصنيف (كما يحفظه الاستيراد) يجب ان يذهب معه، والا ظن الاستيراد التالي ان التصنيف موجود.
        \Illuminate\Support\Facades\DB::table('legacy_links')->insert(['source' => 'itqan', 'source_table' => 'catalog-categories', 'source_key' => '00001', 'target_type' => Category::class, 'target_id' => 999, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('legacy_links')->insert(['source' => 'itqan', 'source_table' => 'customers', 'source_key' => 'C1', 'target_type' => User::class, 'target_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \Modules\Items\Entities\Finish::create(['name' => 'لون من استيراد سابق', 'is_active' => true]);
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
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('legacy_links')->where('target_type', Category::class)->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('legacy_links')->where('target_type', User::class)->count());
        // الوان الاستيراد السابق تُحذف، ويبقى «بلا لون» النظامي.
        $this->assertSame(0, \Modules\Items\Entities\Finish::query()->where('is_system', false)->count());
        $this->assertSame(1, \Modules\Items\Entities\Finish::query()->where('is_system', true)->count());
    }
}
