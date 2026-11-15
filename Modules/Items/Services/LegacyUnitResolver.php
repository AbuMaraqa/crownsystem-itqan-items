<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;

/**
 * وحدة الصنف القديم (عمود itemunit: كغم، عامود، قطعة، مغلق، حبة) في جدول الوحدات: تُربط
 * بالوحدة التي لها الاسم نفسه، وتُنشأ عند اول استخدام. النص الاصلي يبقى في meta_data.unit.
 */
class LegacyUnitResolver
{
    /**
     * @var array<string, int>
     */
    private array $ids = [];

    private int $created = 0;

    public function findOrCreate(mixed $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return $this->ids[mb_strtolower($name)] ??= $this->find($name) ?? $this->create($name);
    }

    public function createdCount(): int
    {
        return $this->created;
    }

    private function find(string $name): ?int
    {
        $prefix = config('items.table_prefix');

        $id = DB::table($prefix.'units as units')
            ->join($prefix.'unit_translations as translations', 'translations.unit_id', '=', 'units.id')
            ->whereNull('units.deleted_at')
            ->where('translations.name', $name)
            ->orderBy('units.id')
            ->value('units.id');

        return $id === null ? null : (int) $id;
    }

    private function create(string $name): int
    {
        $prefix = config('items.table_prefix');
        $now = now();
        $slug = 'legacy-unit-'.substr(sha1(mb_strtolower($name)), 0, 12);

        // وحدة مستوردة سابقاً ثم محذوفة تعود بدل التصادم على slug الفريد.
        $existing = DB::table($prefix.'units')->where('slug', $slug)->value('id');

        if ($existing !== null) {
            DB::table($prefix.'units')->where('id', $existing)->update(['deleted_at' => null, 'updated_at' => $now]);

            return (int) $existing;
        }

        $id = (int) DB::table($prefix.'units')->insertGetId(['slug' => $slug, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now]);

        DB::table($prefix.'unit_translations')->insert(['unit_id' => $id, 'locale' => 'ar', 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);

        $this->created++;

        return $id;
    }
}
