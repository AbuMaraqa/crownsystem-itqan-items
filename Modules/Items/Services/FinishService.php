<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\Finish;
use Modules\Items\Support\ItemCode;

/**
 * قائمة الألوان (Finish). كل لون له قيمة في سمة «اللون» النظامية يقرأ منها المتجر ألوان المتغيرات
 * (R-6)؛ اللون هو المصدر والقيمة صورة منه: اسمها ولون عرضها يُكتبان معه هنا فقط. النقل يكتب
 * الصورة دفعة واحدة (CatalogImporter::mirrorFinishes).
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

            $finish ??= Finish::create([
                'name' => $name,
                'is_active' => true,
                'sort_order' => (int) Finish::query()->max('sort_order') + 1,
                'created_by' => $userId,
            ]);

            if ($finish->attribute_value_id === null) {
                $this->mirror($finish, $userId);
            }

            return $finish;
        });
    }

    /**
     * لون جديد من صفحة «الألوان». اسم لون محذوف سابقاً يعيده بدل تكرار الاسم.
     *
     * @param  array{name: string, code?: string|null, color_code?: string|null, is_active?: bool}  $data
     */
    public function create(array $data, ?int $userId): Finish
    {
        return DB::transaction(function () use ($data, $userId): Finish {
            $finish = $this->findOrCreate($data['name'], $userId);

            return $this->write($finish, $data);
        });
    }

    /**
     * @param  array{name: string, code?: string|null, color_code?: string|null, is_active?: bool}  $data
     *
     * @throws DomainException «بلا لون» النظامي لا يُعطَّل ولا يُعاد تسميته
     */
    public function update(Finish $finish, array $data): Finish
    {
        if ($finish->is_system && (trim($data['name']) !== $finish->name || ! ($data['is_active'] ?? true))) {
            throw new DomainException(__('The system colour «No colour» cannot be renamed or deactivated.'));
        }

        return DB::transaction(fn (): Finish => $this->write($finish, $data));
    }

    /**
     * @throws DomainException «بلا لون» النظامي، او لون يستخدمه تصنيف او صنف
     */
    public function delete(Finish $finish): void
    {
        if ($finish->is_system) {
            throw new DomainException(__('The system colour «No colour» cannot be deleted.'));
        }

        if ($finish->isUsed()) {
            throw new DomainException(__('This colour is used by categories or items; deactivate it instead.'));
        }

        DB::transaction(function () use ($finish): void {
            $finish->attributeValue?->delete();
            $finish->forceFill(['attribute_value_id' => null])->save();
            $finish->delete();
        });
    }

    /**
     * @param  array{name: string, code?: string|null, color_code?: string|null, is_active?: bool}  $data
     */
    private function write(Finish $finish, array $data): Finish
    {
        $finish->fill([
            'name' => trim($data['name']),
            // تفريغ الرقم يُبقي رقمه؛ اللون لا يبقى بلا رقم.
            'code' => filled($data['code'] ?? null) ? trim($data['code']) : ($finish->code ?? ItemCode::nextFinish()),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        $value = $finish->attributeValue;
        $value->color_code = filled($data['color_code'] ?? null) ? $data['color_code'] : null;
        $value->translateOrNew(app()->getLocale())->name = $finish->name;
        $value->save();

        return $finish;
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
