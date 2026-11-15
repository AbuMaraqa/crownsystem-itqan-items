<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\PriceList;
use Modules\Items\Repositories\Contracts\PriceListRepositoryInterface;

/**
 * كتابة قوائم الاسعار. توحيد اسعار الشراء مع البيع عند تفعيل same_buy_sell، ومنع حذف
 * قائمة فيها اسعار او مربوطة بطرف، كلاهما في النموذج نفسه (PriceList).
 */
class PriceListService
{
    private const TRANSLATED = ['name'];

    public function __construct(private readonly PriceListRepositoryInterface $lists) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): PriceList
    {
        return DB::transaction(function () use ($data, $locale, $userId): PriceList {
            /** @var PriceList $list */
            $list = $this->lists->query()->newModelInstance();
            $list->fill([...$data, 'created_by' => $userId]);
            Translations::fill($list, Translations::fromForm($data, $locale, self::TRANSLATED));
            $list->save();

            return $list;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PriceList $list, array $data, string $locale): PriceList
    {
        unset($data['created_by']);

        return DB::transaction(function () use ($list, $data, $locale): PriceList {
            $list->fill($data);
            Translations::fill($list, Translations::fromForm($data, $locale, self::TRANSLATED));
            $list->save();

            return $list;
        });
    }

    /**
     * DomainException برسالة السبب اذا كانت القائمة مستخدمة؛ false اذا رفضت قاعدة البيانات الحذف.
     */
    public function delete(PriceList $list): bool
    {
        return SafeDelete::attempt(fn () => $this->lists->delete($list));
    }

    /**
     * يحذف القوائم غير المستخدمة ويترك الباقي، ويعيد عدد ما بقي.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): int
    {
        $kept = 0;

        $this->lists->query()->whereKey($ids)->get()->each(function (PriceList $list) use (&$kept): void {
            try {
                $kept += $this->delete($list) ? 0 : 1;
            } catch (DomainException) {
                $kept++;
            }
        });

        return $kept;
    }
}
