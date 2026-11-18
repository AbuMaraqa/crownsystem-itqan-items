<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

use Illuminate\Support\Facades\DB;

/**
 * قراءة وكتابة جدول legacy_links: المعرّف القديم في إتقان => معرّف السجل الجديد.
 * الكتابة upsert على المفتاح الفريد، فتعمل على MySQL وPostgreSQL.
 */
final class LegacyLinks
{
    public const SOURCE = 'itqan';

    /**
     * @param  array<int, string>|null  $keys  مفاتيح محددة، او كل ما في الجدول القديم
     * @return array<string, int> المفتاح القديم => المعرّف الجديد
     */
    public function map(string $sourceTable, string $targetType, ?array $keys = null): array
    {
        $query = DB::table('legacy_links')
            ->where('source', self::SOURCE)
            ->where('source_table', $sourceTable)
            ->where('target_type', $targetType);

        if ($keys === null) {
            return $query->pluck('target_id', 'source_key')->map(fn ($id): int => (int) $id)->all();
        }

        $map = [];

        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            foreach ((clone $query)->whereIn('source_key', $chunk)->pluck('target_id', 'source_key') as $key => $id) {
                $map[(string) $key] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $links  المفتاح القديم => المعرّف الجديد
     */
    public function remember(string $sourceTable, string $targetType, array $links): void
    {
        $now = now();
        $rows = [];

        foreach ($links as $key => $id) {
            $rows[] = [
                'source' => self::SOURCE,
                'source_table' => $sourceTable,
                'source_key' => (string) $key,
                'target_type' => $targetType,
                'target_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('legacy_links')->upsert(
                $chunk,
                ['source', 'source_table', 'source_key', 'target_type'],
                ['target_id', 'updated_at'],
            );
        }
    }
}
