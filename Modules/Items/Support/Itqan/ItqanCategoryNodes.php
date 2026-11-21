<?php

namespace Modules\Items\Support\Itqan;

/**
 * عقد التصنيفات في جدول items بإتقان: كل صف له أبناء هو تصنيف، ومعه عدد
 * أوراقه المباشرة (الاصناف التي تحته ولا أبناء لها).
 */
final class ItqanCategoryNodes
{
    /**
     * @param  iterable<int, array<string, mixed>>  $itemRows
     * @return array<string, array{ItemId: string, ItemString: string, ItemParent: string, isActive: string, items: int}>
     */
    public static function fromRows(iterable $itemRows): array
    {
        $rows = [];
        $childCount = [];

        foreach ($itemRows as $row) {
            $id = trim((string) $row['ItemId']);
            $parent = trim((string) ($row['ItemParent'] ?? '0'));
            $rows[$id] = [
                'ItemId' => $id,
                'ItemString' => trim((string) $row['ItemString']),
                'ItemParent' => $parent,
                'isActive' => trim((string) ($row['isActive'] ?? 'Y')),
            ];

            if ($parent !== $id) {
                $childCount[$parent] = ($childCount[$parent] ?? 0) + 1;
            }
        }

        $leaves = [];
        foreach ($rows as $id => $row) {
            if (! isset($childCount[$id])) {
                $leaves[$row['ItemParent']] = ($leaves[$row['ItemParent']] ?? 0) + 1;
            }
        }

        $nodes = [];
        foreach ($rows as $id => $row) {
            if (isset($childCount[$id])) {
                $nodes[$id] = $row + ['items' => $leaves[$id] ?? 0];
            }
        }

        return $nodes;
    }
}
