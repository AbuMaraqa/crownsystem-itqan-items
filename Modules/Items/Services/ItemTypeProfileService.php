<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Repositories\Contracts\ItemTypeProfileRepositoryInterface;

/**
 * كتابة انواع الاصناف. منع حذف نوع يستخدمه صنف (ولو محذوفاً) في النموذج نفسه (ItemTypeProfile).
 */
class ItemTypeProfileService
{
    private const TRANSLATED = ['name'];

    public function __construct(private readonly ItemTypeProfileRepositoryInterface $profiles) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): ItemTypeProfile
    {
        return DB::transaction(function () use ($data, $locale, $userId): ItemTypeProfile {
            /** @var ItemTypeProfile $profile */
            $profile = $this->profiles->query()->newModelInstance();
            $profile->fill([...$data, 'created_by' => $userId]);
            Translations::fill($profile, Translations::fromForm($data, $locale, self::TRANSLATED));
            $profile->save();

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ItemTypeProfile $profile, array $data, string $locale): ItemTypeProfile
    {
        unset($data['created_by']);

        return DB::transaction(function () use ($profile, $data, $locale): ItemTypeProfile {
            $profile->fill($data);
            Translations::fill($profile, Translations::fromForm($data, $locale, self::TRANSLATED));
            $profile->save();

            return $profile;
        });
    }

    /**
     * DomainException برسالة السبب اذا كان النوع مستخدماً؛ false اذا رفضت قاعدة البيانات الحذف.
     */
    public function delete(ItemTypeProfile $profile): bool
    {
        return SafeDelete::attempt(fn () => $this->profiles->delete($profile));
    }

    /**
     * يحذف الانواع غير المستخدمة ويترك الباقي، ويعيد عدد ما بقي.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): int
    {
        $kept = 0;

        $this->profiles->query()->whereKey($ids)->get()->each(function (ItemTypeProfile $profile) use (&$kept): void {
            try {
                $kept += $this->delete($profile) ? 0 : 1;
            } catch (DomainException) {
                $kept++;
            }
        });

        return $kept;
    }
}
