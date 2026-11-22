<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\Finish;

/**
 * قائمة الألوان (Finish). كل لون له قيمة في سمة «اللون» النظامية يقرأ منها المتجر ألوان المتغيرات
 * (R-6)؛ اللون هو المصدر والقيمة صورة منه. النقل يكتب الصورة دفعة واحدة (CatalogImporter::mirrorFinishes).
 */
class FinishService
{
    /**
     * اللون بهذا الاسم، او لون جديد مع صورته في سمة «اللون».
     */
    public function findOrCreate(string $name, ?int $userId): Finish
    {
        $name = trim($name);

        return DB::transaction(function () use ($name, $userId): Finish {
            $finish = Finish::withTrashed()->where('name', $name)->first();

            if ($finish?->trashed()) {
                $finish->restore();
            }

            $finish ??= Finish::create(['name' => $name, 'is_active' => true, 'created_by' => $userId]);

            if ($finish->attribute_value_id === null) {
                $this->mirror($finish, $userId);
            }

            return $finish;
        });
    }

    /**
     * صورة اللون في سمة «اللون».
     */
    private function mirror(Finish $finish, ?int $userId): void
    {
        $value = new AttributeValue(['attribute_id' => Finish::colourAttributeId(), 'created_by' => $userId]);
        $value->translateOrNew(app()->getLocale())->name = $finish->name;
        $value->save();

        $finish->forceFill(['attribute_value_id' => $value->id])->save();
    }
}
