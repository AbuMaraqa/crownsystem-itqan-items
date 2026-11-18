<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\ProductSearch;
use Tests\TestCase;

/**
 * سعر سطر المستند بترتيب النظام القديم: آخر سعر للطرف (بخيار)، ثم قائمة اسعاره،
 * ثم السلوك القديم. بلا طرف لا سعر تلقائي، وسعر سطر الوزن للصنف الرئيسي.
 */
class DocumentLinePriceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->product = ConsolidatedTestFactory::product();
        // التراجع: السعر الاساسي (10) للبيع، ومتوسط التكلفة من المشتريات (8) للشراء ولهامش الزبون.
        ProductCost::query()->create(['product_id' => $this->product->id, 'quantity' => 1, 'value' => 8, 'average_cost' => 8]);
    }

    public function test_no_party_means_no_automatic_price(): void
    {
        $this->assertSame('0.0000', $this->salePrice(null));
        $this->assertSame('0.0000', ProductSearch::invoiceItemPayload($this->product->id, LinePriceContext::for(null))['unit_price']);
    }

    public function test_without_a_context_the_old_callers_keep_their_price(): void
    {
        $this->assertSame('10.0000', ProductSearch::salesItemPayload($this->product->id)['unit_price']);
        $this->assertSame('8.0000', ProductSearch::invoiceItemPayload($this->product->id)['unit_price']);
    }

    public function test_a_party_without_settings_falls_back_to_the_old_behaviour(): void
    {
        $party = User::factory()->create();

        $this->assertSame('10.0000', $this->salePrice($party->id));
        $this->assertSame('10.0000', ProductSearch::salesItemPayload($this->product->id, 25, LinePriceContext::for($party->id))['unit_price']);
        $this->assertSame('8.0000', ProductSearch::invoiceItemPayload($this->product->id, LinePriceContext::for($party->id))['unit_price']);
    }

    public function test_last_price_is_opt_in(): void
    {
        $party = User::factory()->create();
        $this->salesInvoice($party, 33);

        $this->assertSame('10.0000', $this->salePrice($party->id));

        $party->update(['use_last_price' => true]);

        $this->assertSame('33.0000', $this->salePrice($party->id));
    }

    public function test_last_price_comes_from_the_most_recent_posted_line_only(): void
    {
        $party = User::factory()->create(['use_last_price' => true]);

        $this->salesInvoice($party, 20, now()->subDays(5)->toDateString());
        $this->salesInvoice($party, 25, now()->subDays(1)->toDateString());
        $this->salesInvoice($party, 22, now()->subDays(3)->toDateString());
        // المسودة والملغاة ليستا سعراً معتمداً، ولو كانتا احدث.
        $this->salesInvoice($party, 90, now()->toDateString(), SalesInvoiceStatus::DRAFT->value);
        $this->salesInvoice($party, 91, now()->toDateString(), SalesInvoiceStatus::CANCELLED->value);
        // ولا سعر طرف آخر.
        $this->salesInvoice(User::factory()->create(), 92);

        $this->assertSame('25.0000', $this->salePrice($party->id));
    }

    public function test_a_zero_last_price_falls_through_to_the_price_list(): void
    {
        $list = $this->priceList(sell: 40, buy: 30);
        $party = User::factory()->create(['use_last_price' => true, 'price_list_id' => $list->id]);
        $this->salesInvoice($party, 0);

        $this->assertSame('40.0000', $this->salePrice($party->id));
    }

    public function test_last_price_is_converted_to_the_currency_of_the_new_document(): void
    {
        $party = User::factory()->create(['use_last_price' => true]);
        // 10 بعملة سعرها 3.5 = 35 بعملة الاساس = 14 بعملة سعرها 2.5.
        $this->salesInvoice($party, 10, null, null, 3.5);

        $this->assertSame('14.0000', ProductSearch::salesItemPayload($this->product->id, null, LinePriceContext::for($party->id, 2.5))['unit_price']);
    }

    public function test_price_list_takes_its_side_for_each_document(): void
    {
        $list = $this->priceList(sell: 40, buy: 30);
        $party = User::factory()->create(['price_list_id' => $list->id]);

        $this->assertSame('40.0000', $this->salePrice($party->id));
        $this->assertSame('30.0000', ProductSearch::invoiceItemPayload($this->product->id, LinePriceContext::for($party->id))['unit_price']);
        $this->assertSame('20.0000', ProductSearch::salesItemPayload($this->product->id, null, LinePriceContext::for($party->id, 2))['unit_price']);
    }

    public function test_purchase_side_reads_purchase_invoices_not_sales(): void
    {
        $party = User::factory()->create(['use_last_price' => true]);
        $this->salesInvoice($party, 55);

        $this->assertSame('8.0000', ProductSearch::invoiceItemPayload($this->product->id, LinePriceContext::for($party->id))['unit_price']);

        $this->purchaseInvoice($party, 44);

        $this->assertSame('44.0000', ProductSearch::invoiceItemPayload($this->product->id, LinePriceContext::for($party->id))['unit_price']);
        $this->assertSame('55.0000', $this->salePrice($party->id));
    }

    public function test_the_customer_group_settings_apply_and_the_party_overrides_them(): void
    {
        $list = $this->priceList(sell: 40, buy: 30);
        $other = $this->priceList(sell: 41, buy: 31);
        $group = CustomerGroup::create(['name' => 'جملة '.Str::random(4), 'profit_margin' => 0, 'use_last_price' => true, 'price_list_id' => $list->id]);
        $party = User::factory()->create(['customer_group_id' => $group->id]);

        $this->assertSame('40.0000', $this->salePrice($party->id));

        $this->salesInvoice($party, 33);
        $this->assertSame('33.0000', $this->salePrice($party->id));

        $party->update(['use_last_price' => false, 'price_list_id' => $other->id]);
        $this->assertSame('41.0000', $this->salePrice($party->id));

        // التصنيف الموقوف لا يسعّر، كما في نسبة الربح.
        $party->update(['use_last_price' => null, 'price_list_id' => null]);
        $group->update(['is_active' => false]);
        $this->assertSame('10.0000', $this->salePrice($party->id));
    }

    public function test_an_item_missing_from_the_list_or_an_inactive_list_falls_back(): void
    {
        $list = PriceList::create(['name' => 'قائمة فارغة']);
        $party = User::factory()->create(['price_list_id' => $list->id]);

        $this->assertSame('10.0000', $this->salePrice($party->id));

        $active = $this->priceList(sell: 40, buy: 30);
        $party->update(['price_list_id' => $active->id]);
        $active->update(['is_active' => false]);

        $this->assertSame('10.0000', $this->salePrice($party->id));
    }

    public function test_a_derived_list_price_is_resolved_through_its_source(): void
    {
        $source = $this->priceList(sell: 40, buy: 30);
        $derived = PriceList::create(['name' => 'مشتقة']);
        ProductPrice::create([
            'price_list_id' => $derived->id,
            'product_id' => $this->product->id,
            'sell_price' => 1,
            'buy_price' => 1,
            'source_price_list_id' => $source->id,
            'source_factor' => 1.5,
        ]);
        $party = User::factory()->create(['price_list_id' => $derived->id]);

        $this->assertSame('60.0000', $this->salePrice($party->id));
    }

    public function test_a_weight_line_is_priced_on_its_main_item(): void
    {
        $main = ConsolidatedTestFactory::product('weight');
        $piece = ConsolidatedTestFactory::product('weight');
        $piece->update(['main_product_id' => $main->id]);

        $list = PriceList::create(['name' => 'رئيسي']);
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $main->id, 'sell_price' => 7, 'buy_price' => 6]);
        $party = User::factory()->create(['price_list_id' => $list->id]);

        $payloads = ProductSearch::salesItemPayloads([$main->id, $piece->id], null, LinePriceContext::for($party->id));

        $this->assertSame('7.0000', $payloads[$main->id]['unit_price']);
        $this->assertSame('7.0000', $payloads[$piece->id]['unit_price']);

        $this->salesInvoice($party->fresh(), 9, null, null, 1, $main);
        $party->update(['use_last_price' => true]);

        $this->assertSame('9.0000', ProductSearch::salesItemPayload($piece->id, null, LinePriceContext::for($party->id))['unit_price']);
    }

    public function test_a_price_list_used_by_a_party_cannot_be_deleted(): void
    {
        $list = PriceList::create(['name' => 'مستعملة']);
        User::factory()->create(['price_list_id' => $list->id]);

        $this->assertNotNull($list->deletionBlocker());
    }

    private function salePrice(?int $partyId): string
    {
        return ProductSearch::salesItemPayload($this->product->id, null, LinePriceContext::for($partyId))['unit_price'];
    }

    private function priceList(float $sell, float $buy): PriceList
    {
        $list = PriceList::create(['name' => 'قائمة '.Str::random(4)]);
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'sell_price' => $sell, 'buy_price' => $buy]);

        return $list;
    }

    private function salesInvoice(User $party, float $price, ?string $date = null, ?int $status = null, float $rate = 1, ?Product $product = null): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'client_id' => $party->id,
            'reference_no' => 'SI-'.Str::random(10),
            'status' => $status ?? SalesInvoiceStatus::POSTED->value,
            'invoice_date' => $date ?? now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => $rate,
        ]);

        $invoice->items()->create(['product_id' => ($product ?? $this->product)->id, 'description' => 'سطر', 'quantity' => 1, 'unit_price' => $price, 'line_total' => $price]);

        return $invoice;
    }

    private function purchaseInvoice(User $party, float $price): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'client_id' => $party->id,
            'reference_no' => 'PI-'.Str::random(10),
            'status' => PurchaseInvoiceStatus::POSTED->value,
            'invoice_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
        ]);

        $invoice->items()->create(['product_id' => $this->product->id, 'description' => 'سطر', 'quantity' => 1, 'unit_price' => $price, 'line_total' => $price]);

        return $invoice;
    }
}
