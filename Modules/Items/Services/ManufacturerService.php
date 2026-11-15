<?php

namespace Modules\Items\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Repositories\Contracts\ManufacturerRepositoryInterface;

/**
 * Writes manufacturers: the slug, the translation of one locale and the image, together.
 */
class ManufacturerService
{
    private const TRANSLATED = ['name', 'description'];

    public function __construct(private readonly ManufacturerRepositoryInterface $manufacturers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $image, string $locale, ?int $userId): Manufacturer
    {
        return DB::transaction(function () use ($data, $image, $locale, $userId): Manufacturer {
            /** @var Manufacturer $manufacturer */
            $manufacturer = $this->manufacturers->query()->newModelInstance();
            $manufacturer->fill(['created_by' => $userId, 'slug' => $data['slug']]);
            Translations::fill($manufacturer, Translations::fromForm($data, $locale, self::TRANSLATED));
            $manufacturer->save();

            if ($image) {
                $manufacturer->addImage($image);
            }

            return $manufacturer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int|string>  $removedImages  ids of saved images the form removed
     */
    public function update(Manufacturer $manufacturer, array $data, ?UploadedFile $image, array $removedImages, string $locale): Manufacturer
    {
        return DB::transaction(function () use ($manufacturer, $data, $image, $removedImages, $locale): Manufacturer {
            $manufacturer->fill(['slug' => $data['slug']]);
            Translations::fill($manufacturer, Translations::fromForm($data, $locale, self::TRANSLATED));
            $manufacturer->save();

            if ($image) {
                $manufacturer->addImage($image); // the collection holds one file: the old one goes
            } elseif ($removedImages !== []) {
                $manufacturer->getMedia('manufacturer')->whereIn('id', $removedImages)->each->delete();
            }

            return $manufacturer;
        });
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط الشركة المصنعة بسجلات اخرى.
     */
    public function delete(Manufacturer $manufacturer): bool
    {
        return SafeDelete::attempt(fn () => $this->manufacturers->delete($manufacturer));
    }

    /**
     * كلها او لا شيء: شركة مرتبطة واحدة تلغي حذف الباقي.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): bool
    {
        return SafeDelete::attempt(fn () => DB::transaction(fn () => $this->manufacturers->query()->whereKey($ids)->get()->each(
            fn (Manufacturer $manufacturer) => $this->manufacturers->delete($manufacturer)
        )));
    }
}
