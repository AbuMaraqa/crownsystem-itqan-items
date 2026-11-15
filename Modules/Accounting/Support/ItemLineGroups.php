<?php

namespace Modules\Accounting\Support;

use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;

/**
 * تجميع سطور مستند للعرض كما في شبكة الاصناف: الصنف الرئيسي (صنف الوزن) صف واحد
 * تحته اصنافه الفرعية، وصنف الكمية صف مستقل. للعرض فقط؛ ترتيب الظهور الاول محفوظ
 * ومفاتيح السطور (فهارسها في مصفوفة الصفحة) باقية كما هي لربط wire:model.
 *
 * كل مجموعة: ['key' => string, 'main' => bool, 'label' => ?string, 'lines' => [index => line]].
 * الشكل نفسه تعرضه مكونات <x-accounting::items.*>.
 */
class ItemLineGroups
{
    /**
     * سطور تحمل اسم صنفها الرئيسي في $labelKey (سند الاستلام وسند التسليم: group)،
     * ومعرّف سطر الطلبية في $parentKey حتى لا يندمج صنفان رئيسيان بالاسم نفسه.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{key: string, main: bool, label: ?string, lines: array<int, array<string, mixed>>}>
     */
    public static function byLabel(array $lines, string $labelKey = 'group', ?string $parentKey = null): array
    {
        $groups = [];

        foreach ($lines as $index => $line) {
            $label = $line[$labelKey] ?? null;

            if (blank($label)) {
                $groups['line-'.$index] = ['key' => 'line-'.$index, 'main' => false, 'label' => null, 'lines' => [$index => $line]];

                continue;
            }

            $key = 'main-'.($parentKey && filled($line[$parentKey] ?? null) ? $line[$parentKey] : md5((string) $label));
            $groups[$key] ??= ['key' => $key, 'main' => true, 'label' => (string) $label, 'lines' => []];
            $groups[$key]['lines'][$index] = $line;
        }

        return array_values($groups);
    }

    /**
     * سطور اصناف مسطّحة (تسوية، جرد، تحويل): تُجمع الاصناف الفرعية تحت صنفها الرئيسي
     * (items_products.main_product_id) باستعلام واحد، والباقي صفوف مستقلة.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{key: string, main: bool, label: ?string, lines: array<int, array<string, mixed>>}>
     */
    public static function byMainItem(array $lines, string $productKey = 'product_id'): array
    {
        $productIds = collect($lines)->pluck($productKey)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $mainOf = $productIds === []
            ? []
            : Product::query()->whereIn('id', $productIds)->whereNotNull('main_product_id')->pluck('main_product_id', 'id')->all();

        $labels = ProductSearch::labels(array_values($mainOf));
        $groups = [];

        foreach ($lines as $index => $line) {
            $mainId = $mainOf[(int) ($line[$productKey] ?? 0)] ?? null;

            if (! $mainId) {
                $groups['line-'.$index] = ['key' => 'line-'.$index, 'main' => false, 'label' => null, 'lines' => [$index => $line]];

                continue;
            }

            $key = 'main-'.$mainId;
            $groups[$key] ??= ['key' => $key, 'main' => true, 'label' => $labels[$mainId] ?? null, 'lines' => []];
            $groups[$key]['lines'][$index] = $line;
        }

        return array_values($groups);
    }
}
