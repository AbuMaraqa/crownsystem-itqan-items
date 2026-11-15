<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Enums\AttributeType;
use Modules\Items\Repositories\Contracts\AttributeRepositoryInterface;

/**
 * Writes attributes and their values (the old "Configure Attribute" window), with the
 * translations of one locale, together.
 */
class AttributeService
{
    private const TRANSLATED = ['name', 'description'];

    public function __construct(private readonly AttributeRepositoryInterface $attributes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $locale, ?int $userId): Attribute
    {
        return DB::transaction(function () use ($data, $locale, $userId): Attribute {
            /** @var Attribute $attribute */
            $attribute = $this->attributes->query()->newModelInstance();
            $attribute->fill(['slug' => $data['slug'], 'type' => $data['type']]);
            // Not fillable on the model, so the old page never saved it.
            $attribute->created_by = $userId;
            Translations::fill($attribute, Translations::fromForm($data, $locale, self::TRANSLATED));
            $attribute->save();

            $this->syncValues($attribute, $data['values'] ?? [], $locale, $userId);

            return $attribute;
        });
    }

    /**
     * Values the form no longer holds are deleted; a request without `values` leaves them alone.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Attribute $attribute, array $data, string $locale, ?int $userId): Attribute
    {
        return DB::transaction(function () use ($attribute, $data, $locale, $userId): Attribute {
            $attribute->fill(['slug' => $data['slug'], 'type' => $data['type']]);
            Translations::fill($attribute, Translations::fromForm($data, $locale, self::TRANSLATED));
            $attribute->save();

            if (array_key_exists('values', $data)) {
                $this->syncValues($attribute, $data['values'] ?? [], $locale, $userId);
            }

            return $attribute;
        });
    }

    /**
     * false when the database refused: the attribute is linked to other records.
     */
    public function delete(Attribute $attribute): bool
    {
        return SafeDelete::attempt(fn () => $this->attributes->delete($attribute));
    }

    /**
     * All or nothing: one linked attribute cancels the whole delete.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): bool
    {
        return SafeDelete::attempt(fn () => DB::transaction(fn () => $this->attributes->query()->whereKey($ids)->get()->each(
            fn (Attribute $attribute) => $this->attributes->delete($attribute)
        )));
    }

    /**
     * The attribute's values become the given rows, in their order. A color only belongs to a color attribute.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncValues(Attribute $attribute, array $rows, string $locale, ?int $userId): void
    {
        // validated() builds the list in rule order, so a row with an id can come first: the key is its position.
        ksort($rows);

        $existing = $attribute->attributeValues()->with('translations')->get()->keyBy('id');
        $isColor = (int) $attribute->type === AttributeType::COLOR->value;

        $existing->except(array_filter(array_column($rows, 'id')))->each(fn (AttributeValue $value) => $value->delete());

        foreach (array_values($rows) as $position => $row) {
            /** @var AttributeValue $value */
            $value = $existing->get($row['id'] ?? null) ?? $attribute->attributeValues()->make(['created_by' => $userId]);
            $translations = Translations::fromForm($row, $locale, self::TRANSLATED);

            $value->fill([
                'color_code' => $isColor ? ($row['color_code'] ?? null) : null,
                'sort_order' => $position,
            ]);
            Translations::fill($value, $translations);
            $value->save();

            // The model's `created` hook copies the request's name and description (the attribute's)
            // into a new value: write the value's own again.
            if ($value->wasRecentlyCreated) {
                Translations::fill($value, $translations);
                $value->save();
            }
        }
    }
}
