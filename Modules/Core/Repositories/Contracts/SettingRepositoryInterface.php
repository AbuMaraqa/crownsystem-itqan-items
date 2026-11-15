<?php

namespace Modules\Core\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Core\Entities\AppVersion;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\Settings;

interface SettingRepositoryInterface extends RepositoryInterface
{
    /**
     * السجل الذي يحمل شعار الموقع (مجموعة الوسائط logo)، يُنشأ ان لم يوجد.
     */
    public function logoHolder(): Settings;

    /**
     * @return Collection<int, AppVersion>
     */
    public function appVersions(): Collection;

    /**
     * @param  array<string, mixed>  $version
     */
    public function saveAppVersion(array $version): void;

    /**
     * @param  list<string>  $platforms
     */
    public function deleteAppVersionsExcept(array $platforms): void;

    /**
     * @return Collection<int, Currency>
     */
    public function currencies(): Collection;

    /**
     * العملة الاساسية حين لم تُختر بعد: الافتراضية، ثم اول عملة.
     */
    public function fallbackCurrencyId(): ?int;

    /**
     * بنود التكاليف الاضافية، مرتبة بالاسم.
     *
     * @return Collection<int, AdditionalCostType>
     */
    public function additionalCostTypes(): Collection;

    /**
     * يحفظ البنود المرسلة، ويحذف حذفاً ناعماً ما أُزيل منها (التكاليف المرتبطة تبقى).
     *
     * @param  list<array{id?: int|null, name: string, is_active?: bool}>  $types
     */
    public function syncAdditionalCostTypes(array $types, ?int $userId): void;
}
