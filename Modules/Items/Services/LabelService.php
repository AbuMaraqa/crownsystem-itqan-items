<?php

namespace Modules\Items\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Label;
use Modules\Items\Repositories\Contracts\LabelRepositoryInterface;

/**
 * Writes labels: columns and the translation of one locale, together. The `icon` column is shown
 * in the table but no form edits it (as in the Livewire page).
 */
class LabelService
{
    private const COLUMNS = ['slug', 'text_color', 'background_color'];

    private const TRANSLATED = ['name', 'description'];

    public function __construct(private readonly LabelRepositoryInterface $labels) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): Label
    {
        return DB::transaction(function () use ($data, $locale, $userId): Label {
            /** @var Label $label */
            $label = $this->labels->query()->newModelInstance();
            $label->fill(['created_by' => $userId, ...Arr::only($data, self::COLUMNS)]);
            Translations::fill($label, Translations::fromForm($data, $locale, self::TRANSLATED));
            $label->save();

            return $label;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Label $label, array $data, string $locale): Label
    {
        return DB::transaction(function () use ($label, $data, $locale): Label {
            $label->fill(Arr::only($data, self::COLUMNS));
            Translations::fill($label, Translations::fromForm($data, $locale, self::TRANSLATED));
            $label->save();

            return $label;
        });
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط الملصق بسجلات اخرى.
     */
    public function delete(Label $label): bool
    {
        return SafeDelete::attempt(fn () => $this->labels->delete($label));
    }
}
