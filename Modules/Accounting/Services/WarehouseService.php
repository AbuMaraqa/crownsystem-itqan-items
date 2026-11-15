<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;

/**
 * اضافة المستودعات وتعديلها وحذفها. الاسم والوصف والعنوان مترجمة: النموذج يحرر لغة الواجهة.
 * حساب المخزون لا يُكتب هنا؛ يُنشأ عند اول ترحيل على المستودع.
 */
class WarehouseService
{
    private const TRANSLATED = ['name', 'description', 'address'];

    private const FIELDS = ['code', 'branch_id', 'phone', 'email', 'latitude', 'longitude', 'is_active'];

    public function __construct(private readonly WarehouseRepositoryInterface $warehouses) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): Warehouse
    {
        return DB::transaction(function () use ($data, $locale, $userId): Warehouse {
            /** @var Warehouse $warehouse */
            $warehouse = $this->warehouses->query()->newModelInstance();
            $warehouse->created_by = $userId;

            return $this->save($warehouse, $data, $locale);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data, string $locale): Warehouse
    {
        return DB::transaction(fn (): Warehouse => $this->save($warehouse, $data, $locale));
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط المستودع بسجلات اخرى.
     */
    public function delete(Warehouse $warehouse): bool
    {
        return SafeDelete::attempt(fn () => DB::transaction(fn () => $this->warehouses->delete($warehouse)));
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): bool
    {
        return SafeDelete::attempt(fn () => DB::transaction(fn () => $this->warehouses->query()->whereKey($ids)->get()->each(
            fn (Warehouse $warehouse) => $this->warehouses->delete($warehouse)
        )));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Warehouse $warehouse, array $data, string $locale): Warehouse
    {
        $warehouse->fill(Arr::only($data, self::FIELDS));
        Translations::fill($warehouse, Translations::fromForm($data, $locale, self::TRANSLATED));
        $warehouse->save();

        return $warehouse;
    }
}
