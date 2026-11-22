<?php

namespace Modules\Items\Support\Itqan;

/**
 * العيّنة الصغيرة لتجربة الكتالوج الجديد (php artisan itqan:import items --sample):
 * - الوبين بأربعة ألوان: أول 30 مقطعاً من «الوبين بدون دهان» (00009، ومنها W45 = 00009001)
 *   مع المقاطع بالاسم نفسه في الأبيض (00001) والميش (00002) والانودايز (00043).
 * - أول 20 إكسسواراً من «قطع الوبين» (000200349).
 * - أول مطاط من «كوشوك تيرمو بالوزن» (00018): موزون بلا لون.
 * - أول صنف من «حذف» (00035): ليظهر في تقرير المتخطّى.
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.3.
 */
final class CatalogSample
{
    public const PROFILES_NODE = '00009';

    public const COLOUR_NODES = ['00001', '00002', '00043'];

    public const PROFILES = 30;

    /** @var array<string, int> عقدة => عدد الأوراق المأخوذة من اولها */
    public const FIXED = ['000200349' => 20, '00018' => 1, '00035' => 1];

    /**
     * @param  array<int, array<string, mixed>>  $itemRows
     * @return array<int, array<string, mixed>>  الصفوف المختارة (العقد وأوراقها) بشكلها الاصلي
     */
    public static function select(array $itemRows): array
    {
        $byId = [];
        $hasChildren = [];
        $leavesByParent = [];

        foreach ($itemRows as $row) {
            $id = trim((string) $row['ItemId']);
            $byId[$id] = $row;
            $parent = trim((string) ($row['ItemParent'] ?? '0'));
            if ($parent !== $id) {
                $hasChildren[$parent] = true;
            }
        }

        foreach ($byId as $id => $row) {
            if (! ItqanCategoryNodes::isCategory((string) $id, (string) ($row['ItemType'] ?? ''), $hasChildren)) {
                $leavesByParent[trim((string) $row['ItemParent'])][] = (string) $id;
            }
        }

        foreach ($leavesByParent as $parent => $ids) {
            sort($ids, SORT_STRING);
            $leavesByParent[$parent] = $ids;
        }

        $selected = [];
        $names = [];
        foreach ($leavesByParent[self::PROFILES_NODE] ?? [] as $id) {
            $name = ItqanNames::normalize((string) $byId[$id]['ItemString']);
            if (! isset($names[$name]) && count($names) >= self::PROFILES) {
                continue;
            }
            $names[$name] = true;
            $selected[$id] = true;
        }

        foreach (self::COLOUR_NODES as $node) {
            foreach ($leavesByParent[$node] ?? [] as $id) {
                if (isset($names[ItqanNames::normalize((string) $byId[$id]['ItemString'])])) {
                    $selected[$id] = true;
                }
            }
        }

        foreach (self::FIXED as $node => $count) {
            foreach (array_slice($leavesByParent[$node] ?? [], 0, $count) as $id) {
                $selected[$id] = true;
            }
        }

        foreach (array_keys($selected) as $id) {
            $parent = trim((string) $byId[$id]['ItemParent']);
            while ($parent !== '0' && isset($byId[$parent]) && ! isset($selected[$parent])) {
                $selected[$parent] = true;
                $parent = trim((string) $byId[$parent]['ItemParent']);
            }
        }

        return array_values(array_intersect_key($byId, $selected));
    }

    /**
     * ربط العقد التي في العيّنة فقط، فلا تُنشأ تصنيفات وألوان إتقان كلها لعيّنة صغيرة.
     *
     * @param  array<string, CategoryMapEntry>  $map
     * @param  array<int, array<string, mixed>>  $sampleRows
     * @return array<string, CategoryMapEntry>
     */
    public static function restrictMap(array $map, array $sampleRows): array
    {
        $ids = array_flip(array_map(fn (array $row): string => trim((string) $row['ItemId']), $sampleRows));

        return array_intersect_key($map, $ids);
    }
}
