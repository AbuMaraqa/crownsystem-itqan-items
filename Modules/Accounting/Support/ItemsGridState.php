<?php

namespace Modules\Accounting\Support;

use Modules\Items\Support\PriceGroupSearch;
use Modules\Items\Support\ProductSearch;

/**
 * حالة جدول الاصناف الموحد (order-items-grid) من سطور مستند محفوظ وقطعها: صف لكل سطر
 * وتحته اصنافه الفرعية، واسماء الاصناف باستعلام واحد. مصدر واحد لكل صفحات البنود.
 */
class ItemsGridState
{
    /**
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $items  سطور بعلاقتي product و pieces
     * @param  array<int, string>  $rowKeys  اعمدة اضافية تُنقل من السطر كما هي (مثل purchase_order_item_id)
     * @param  array<int, string>  $pieceKeys  اعمدة اضافية تُنقل من القطعة كما هي
     * @param  (callable(\Illuminate\Database\Eloquent\Model): array<string, mixed>)|null  $extraPiece
     * @param  bool  $quantityIsWeight  كمية سطر الوزن كيلوغرامات: السطر القديم بلا قطع يُعرض بها وزناً فعلياً
     * @return array<int, array<string, mixed>>
     */
    public static function rows(iterable $items, array $rowKeys = [], array $pieceKeys = [], ?callable $extraPiece = null, bool $quantityIsWeight = false): array
    {
        $items = collect($items)->values();
        $productIds = $items->pluck('product_id')
            ->merge($items->flatMap(fn ($item) => $item->pieces->pluck('product_id')))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $labels = ProductSearch::labels($productIds);
        // سطر مجموعة السعر (بلا صنف): اسمه «التصنيف — اللون»، وهو سطر وزن قطعه ألوان مقاطعه.
        $groupLabels = PriceGroupSearch::labels($items->filter(fn ($item) => $item->product_id === null)->pluck('category_finish_id')->filter()->all());

        return $items->map(function ($item) use ($labels, $groupLabels, $rowKeys, $pieceKeys, $extraPiece, $quantityIsWeight): array {
            $groupId = $item->product_id === null && $item->category_finish_id ? (int) $item->category_finish_id : null;
            $label = $groupId ? ($groupLabels[$groupId] ?? (string) $item->description) : ($labels[$item->product_id] ?? ProductSearch::label($item->product_id));
            $pricingType = $groupId ? 'weight' : ($item->product?->pricing_type ?? 'simple');
            $actualWeight = $item->actual_weight ?? null;

            if ($quantityIsWeight && $pricingType === 'weight' && $item->pieces->isEmpty() && ! ((float) $actualWeight > 0)) {
                $actualWeight = $item->quantity;
            }

            $row = [
                '_key' => 'item-'.$item->id,
                'id' => $item->id,
                'row_type' => 'item',
                'product_id' => $item->product_id,
                'category_finish_id' => $groupId,
                'product_label' => $label,
                'product_search' => $label,
                'pricing_type' => $pricingType,
                // داخل سطر مجموعة السعر يقتصر بحث القطع على ألوانها.
                'has_sub_products' => $groupId !== null,
                'barcode' => $item->barcode,
                'description' => $item->description,
                // سطر وزن بقطعه وبلا وزن فعلي كميته المفوترة صفر؛ كمية صف الوزن لا تُكتب في
                // الجدول والخدمة تعيد حسابها، فلا يمنع الصفر اعادة حفظ المسودة.
                'quantity' => $pricingType === 'weight' && $item->pieces->isNotEmpty() && (float) $item->quantity <= 0 ? 1 : $item->quantity,
                'total_weight' => $item->total_weight ?? '0.0000',
                'actual_weight' => $actualWeight,
                // سطر بلا وزن فعلي مُدخل لا يُقارَن. تنبيه فقط، ولا يدخل في الحفظ.
                'weight_variance_exceeded' => $pricingType === 'weight'
                    && (float) $actualWeight > 0
                    && WeightPieces::weightVarianceExceeded($actualWeight, $item->total_weight ?? 0),
                'unit_price' => $item->unit_price,
                'discount_mode' => 'rate',
                'discount_rate' => $item->discount_rate,
                'discount_amount' => $item->discount_amount,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
            ];

            foreach ($rowKeys as $key) {
                $row[$key] = $item->{$key};
            }

            $row['pieces'] = $item->pieces->map(function ($piece) use ($labels, $pieceKeys, $extraPiece): array {
                $pieceRow = [
                    '_key' => 'piece-'.$piece->id,
                    'id' => $piece->id,
                    'product_id' => $piece->product_id,
                    'product_label' => $labels[$piece->product_id] ?? $piece->description,
                    'barcode' => $piece->barcode,
                    'description' => $piece->description,
                    'piece_count' => $piece->piece_count,
                    'piece_length' => $piece->piece_length,
                    'weight_per_meter' => $piece->weight_per_meter,
                    'total_weight' => $piece->total_weight,
                ];

                foreach ($pieceKeys as $key) {
                    $pieceRow[$key] = $piece->{$key};
                }

                return $extraPiece ? array_replace($pieceRow, $extraPiece($piece)) : $pieceRow;
            })->values()->all();

            return $row;
        })->all();
    }
}
