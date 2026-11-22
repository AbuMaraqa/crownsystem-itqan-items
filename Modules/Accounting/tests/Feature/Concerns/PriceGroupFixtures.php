<?php

namespace Modules\Accounting\tests\Feature\Concerns;

use Illuminate\Support\Str;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;

/**
 * مجموعات سعر (تصنيف × لون) ومقاطعها الملونة لاختبارات سطور المستندات: «الوبين» بلونين
 * (أبيض وميش) ومقطع W45 بكل لون، وسطر مجموعة جاهز للإرسال من جدول الاصناف.
 */
trait PriceGroupFixtures
{
    /**
     * W45 وزن قطعته 6.5 × 1.25 = 8.125 كغم، و W60 (أبيض فقط) 6.5 × 2 = 13 كغم.
     *
     * @return array{white: CategoryFinish, mesh: CategoryFinish, whiteW45: Product, meshW45: Product, whiteW60: Product}
     */
    protected function alpineGroups(): array
    {
        $alpine = Category::create(['slug' => 'alpine-'.Str::lower(Str::random(5)), 'status' => 1, 'pricing_type' => 'weight']);
        $alpine->translateOrNew('ar')->name = 'الوبين';
        $alpine->save();
        $white = CategoryFinish::create(['category_id' => $alpine->id, 'finish_id' => Finish::create(['name' => 'أبيض', 'is_active' => true])->id, 'is_active' => true]);
        $mesh = CategoryFinish::create(['category_id' => $alpine->id, 'finish_id' => Finish::create(['name' => 'ميش', 'is_active' => true])->id, 'is_active' => true]);
        $variation = function (string $name, CategoryFinish $group, float $weightPerMeter = 1.25): Product {
            $product = new Product(['slug' => 'v-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'weight', 'weight_per_meter' => $weightPerMeter, 'length' => 6.5, 'category_finish_id' => $group->id, 'finish_id' => $group->finish_id]);
            $product->save();
            $product->translateOrNew('ar')->name = $name;
            $product->save();

            return $product;
        };

        return [
            'white' => $white,
            'mesh' => $mesh,
            'whiteW45' => $variation('ثابت مجرين W45 - أبيض', $white),
            'meshW45' => $variation('ثابت مجرين W45 - ميش', $mesh),
            'whiteW60' => $variation('حلق W60 - أبيض', $white, 2.0),
        ];
    }

    /**
     * سطر مجموعة السعر كما يرسله جدول الاصناف: بلا صنف، وقطعه مقاطع ملونة.
     *
     * @param  array<int, array{0: Product, 1: int}>  $pieces  [المقطع، عدد القطع]
     * @return array<string, mixed>
     */
    protected function groupLine(CategoryFinish $group, array $pieces, ?float $actual = null, float $price = 20): array
    {
        return [
            '_key' => 'row-g', 'row_type' => 'item', 'product_id' => null, 'category_finish_id' => $group->id,
            'product_label' => 'الوبين — أبيض', 'pricing_type' => 'weight', 'quantity' => 1, 'actual_weight' => $actual,
            'unit_price' => (string) $price, 'discount_mode' => 'rate', 'discount_rate' => 0, 'discount_amount' => 0, 'collapsed' => false,
            'pieces' => array_map(fn (array $piece) => ['_key' => 'p-'.$piece[0]->id, 'product_id' => $piece[0]->id, 'piece_count' => $piece[1], 'piece_length' => 6.5, 'weight_per_meter' => (float) $piece[0]->weight_per_meter], $pieces),
        ];
    }
}
