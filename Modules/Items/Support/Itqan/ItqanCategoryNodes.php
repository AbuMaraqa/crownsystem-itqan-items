<?php

namespace Modules\Items\Support\Itqan;

/**
 * عقد التصنيفات في جدول items بإتقان، ومعها عدد أوراقها المباشرة.
 *
 * العقدة: كل صف له أبناء، او نوعه نوع حاوية (c_cont للتصنيف العام، a_al لمجموعة
 * الالمنيوم) ولو كان فارغاً؛ في إتقان تصنيفات فارغة مثل «بودرة دهان الوبين».
 * هذه القاعدة الوحيدة التي تفصل التصنيف عن الصنف، ويستعملها النقل وملف الربط.
 */
final class ItqanCategoryNodes
{
    public const CATEGORY_TYPES = ['c_cont', 'a_al'];

    /**
     * @param  array<string, true>  $hasChildren
     */
    public static function isCategory(string $id, ?string $type, array $hasChildren): bool
    {
        return isset($hasChildren[$id]) || in_array(trim((string) $type), self::CATEGORY_TYPES, true);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $itemRows
     * @return array<string, array{ItemId: string, ItemString: string, ItemParent: string, isActive: string, items: int}>
     */
    public static function fromRows(iterable $itemRows): array
    {
        $rows = [];
        $types = [];
        $hasChildren = [];

        foreach ($itemRows as $row) {
            $id = trim((string) $row['ItemId']);
            $parent = trim((string) ($row['ItemParent'] ?? '0'));
            $rows[$id] = [
                'ItemId' => $id,
                'ItemString' => trim((string) $row['ItemString']),
                'ItemParent' => $parent,
                'isActive' => trim((string) ($row['isActive'] ?? 'Y')),
            ];
            $types[$id] = (string) ($row['ItemType'] ?? '');

            if ($parent !== $id) {
                $hasChildren[$parent] = true;
            }
        }

        $leaves = [];
        $nodes = [];
        foreach ($rows as $id => $row) {
            if (self::isCategory((string) $id, $types[$id], $hasChildren)) {
                $nodes[$id] = $row;
            } else {
                $leaves[$row['ItemParent']] = ($leaves[$row['ItemParent']] ?? 0) + 1;
            }
        }

        foreach ($nodes as $id => $row) {
            $nodes[$id] = $row + ['items' => $leaves[$id] ?? 0];
        }

        return $nodes;
    }
}
