<?php

namespace Modules\Items\Support;

use Illuminate\Support\Collection;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Support\Search\TextNormalizer;

/**
 * بحث مجموعات السعر (التصنيف × اللون) في خانة صنف المستند: «الوبين اب» يجد «الوبين — أبيض».
 * المجموعات المفعّلة لتصنيفات الوزن فقط، وكل كلمات البحث في اسم التصنيف او اللون، بلا فرق في
 * الهمزات. عددها معدود (مئات)، فتُطابق في الذاكرة.
 */
final class PriceGroupSearch
{
    /**
     * @return array<int, string> المجموعة => «التصنيف — اللون»، الاقرب اولاً
     */
    public static function options(string $term, int $limit = 8): array
    {
        $words = TextNormalizer::words($term);

        if ($words === []) {
            return [];
        }

        return self::groups()
            ->map(fn (CategoryFinish $group) => ['id' => (int) $group->id, 'label' => $group->label(), 'text' => TextNormalizer::normalize($group->label())])
            ->filter(fn (array $group) => collect($words)->every(fn (string $word) => str_contains($group['text'], $word)))
            // ما يبدأ بكلمة البحث الاولى اولاً، ثم بالاسم.
            ->sortBy(fn (array $group) => [str_starts_with($group['text'], $words[0]) ? 0 : 1, $group['label']])
            ->take(max($limit, 1))
            ->mapWithKeys(fn (array $group) => [$group['id'] => $group['label']])
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, string>
     */
    public static function labels(array $ids): array
    {
        return CategoryFinish::query()
            ->with(['category.translations', 'finish'])
            ->whereIn('id', array_filter(array_map('intval', $ids)))
            ->get()
            ->mapWithKeys(fn (CategoryFinish $group) => [(int) $group->id => $group->label()])
            ->all();
    }

    /**
     * @return Collection<int, CategoryFinish>
     */
    private static function groups(): Collection
    {
        return CategoryFinish::query()
            ->with(['category.translations', 'finish'])
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('pricing_type', 'weight'))
            ->whereHas('finish', fn ($query) => $query->where('is_active', true))
            ->get();
    }
}
