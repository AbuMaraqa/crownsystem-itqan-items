<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Unit;
use Modules\Items\Repositories\Contracts\UnitRepositoryInterface;

/**
 * Writes units: the slug and the translation of one locale, together.
 */
class UnitService
{
    private const TRANSLATED = ['name', 'description'];

    public function __construct(private readonly UnitRepositoryInterface $units) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): Unit
    {
        return DB::transaction(function () use ($data, $locale, $userId): Unit {
            /** @var Unit $unit */
            $unit = $this->units->query()->newModelInstance();
            $unit->fill(['created_by' => $userId, 'slug' => $data['slug']]);
            Translations::fill($unit, Translations::fromForm($data, $locale, self::TRANSLATED));
            $unit->save();

            return $unit;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Unit $unit, array $data, string $locale): Unit
    {
        return DB::transaction(function () use ($unit, $data, $locale): Unit {
            $unit->fill(['slug' => $data['slug']]);
            Translations::fill($unit, Translations::fromForm($data, $locale, self::TRANSLATED));
            $unit->save();

            return $unit;
        });
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط الوحدة بسجلات اخرى.
     *
     * @throws DomainException when an item uses the unit (as its base or an equivalent unit)
     */
    public function delete(Unit $unit): bool
    {
        if ($unit->isUsed()) {
            throw new DomainException(__('This unit is used by items and cannot be deleted.'));
        }

        return SafeDelete::attempt(fn () => $this->units->delete($unit));
    }

    /**
     * يحذف غير المستخدم ويترك ما تستخدمه الاصناف.
     *
     * @param  list<int>  $ids
     * @return int how many were kept because they are in use
     */
    public function deleteMany(array $ids): int
    {
        $kept = 0;

        foreach ($this->units->query()->whereKey($ids)->get() as $unit) {
            /** @var Unit $unit */
            if ($unit->isUsed() || ! SafeDelete::attempt(fn () => $this->units->delete($unit))) {
                $kept++;
            }
        }

        return $kept;
    }
}
