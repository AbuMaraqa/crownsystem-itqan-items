<?php

namespace Modules\Core\Repositories;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Core\Entities\AppVersion;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\Settings;
use Modules\Core\Repositories\Contracts\SettingRepositoryInterface;

class SettingRepository extends BaseEloquentRepository implements SettingRepositoryInterface
{
    public function __construct(Settings $model)
    {
        parent::__construct($model);
    }

    public function logoHolder(): Settings
    {
        /** @var Settings $holder */
        $holder = $this->query()->with('media')->orderBy('id')->first() ?? $this->query()->create([]);

        return $holder;
    }

    public function appVersions(): Collection
    {
        return AppVersion::query()->orderBy('id')->get()->toBase();
    }

    public function saveAppVersion(array $version): void
    {
        AppVersion::query()->updateOrCreate(
            ['platform' => $version['platform']],
            [
                'min_version' => $version['min_version'],
                'latest_version' => $version['latest_version'],
                'store_url' => $version['store_url'],
                'maintenance_mode' => (bool) ($version['maintenance_mode'] ?? false),
                'message' => $version['message'] ?? null,
            ],
        );
    }

    public function deleteAppVersionsExcept(array $platforms): void
    {
        AppVersion::query()->whereNotIn('platform', $platforms)->delete();
    }

    public function currencies(): Collection
    {
        return Currency::query()->with('translations')->orderBy('code')->get()->toBase();
    }

    public function fallbackCurrencyId(): ?int
    {
        $id = Currency::query()->where('is_default', true)->value('id') ?? Currency::query()->value('id');

        return $id === null ? null : (int) $id;
    }

    public function additionalCostTypes(): Collection
    {
        return AdditionalCostType::query()->orderBy('name')->get(['id', 'name', 'is_active'])->toBase();
    }

    public function syncAdditionalCostTypes(array $types, ?int $userId): void
    {
        $keep = [];

        foreach ($types as $row) {
            $type = isset($row['id']) ? AdditionalCostType::query()->find($row['id']) : null;
            $values = ['name' => $row['name'], 'is_active' => (bool) ($row['is_active'] ?? true)];

            if ($type) {
                $type->update($values);
            } else {
                // created_by يُسجَّل عند الانشاء فقط حتى لا يُمحى عند اي تعديل لاحق.
                $type = AdditionalCostType::query()->create([...$values, 'created_by' => $userId]);
            }

            $keep[] = $type->id;
        }

        AdditionalCostType::query()->whereNotIn('id', $keep)->delete();
    }
}
