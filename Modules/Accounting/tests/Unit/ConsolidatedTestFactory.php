<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Support\Str;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Core\Entities\Currency;
use Modules\Items\Entities\Product;

/**
 * بيانات دنيا للاختبارات: صنف، عملة، طلبية شراء.
 */
class ConsolidatedTestFactory
{
    public static function currency(): Currency
    {
        return Currency::query()->firstOrCreate(
            ['code' => 'ILS'],
            ['symbol' => '₪', 'is_default' => true]
        );
    }

    public static function product(string $pricingType = 'simple', ?float $length = null): Product
    {
        $product = new Product([
            'slug' => 'test-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => Str::random(12),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 12,
            'pricing_type' => $pricingType,
            'weight_per_meter' => $pricingType === 'weight' ? 1.5 : null,
            'length' => $length,
        ]);

        $product->save();
        $product->translateOrNew('ar')->name = 'صنف اختبار';
        $product->save();

        return $product;
    }

    public static function purchaseOrder(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'reference_no' => 'TEST-'.Str::random(8),
            'order_date' => now()->toDateString(),
            'currency_id' => self::currency()->id,
            'exchange_rate' => 1,
            'status' => 1,
        ]);
    }
}
