<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Category;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;

/**
 * كتابة التصنيفات من لوحة التحكم: الحقول والترجمة والصورة معاً.
 *
 * رقم التصنيف (code) يولّده النموذج عند الانشاء من رقم ابيه (ItemCode)، ومنع وضع التصنيف
 * تحت نفسه في النموذج ايضاً. الحذف عبر ItemTreeService كما في شجرة الاصناف: حذف ناعم،
 * فلا يُعاد استخدام رقم التصنيف المحذوف.
 */
class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly ItemTreeService $tree,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  slug, parent_id
     * @param  array<string, array<string, mixed>>  $translations  locale => [name, description]
     */
    public function create(array $attributes, array $translations, ?UploadedFile $image, ?int $createdBy): Category
    {
        return DB::transaction(function () use ($attributes, $translations, $image, $createdBy): Category {
            /** @var Category $category */
            $category = $this->categories->query()->newModelInstance();
            $category->fill([...$attributes, 'created_by' => $createdBy]);
            Translations::fill($category, $translations);
            $category->save();

            if ($image) {
                $category->addImage($image);
            }

            return $category;
        });
    }

    /**
     * created_by لا يتغير بالتعديل. نقل التصنيف تحت احد فروعه يرفضه النموذج (DomainException).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function update(Category $category, array $attributes, array $translations, ?UploadedFile $image = null): Category
    {
        unset($attributes['created_by'], $attributes['code']);

        return DB::transaction(function () use ($category, $attributes, $translations, $image): Category {
            $category->fill($attributes);
            Translations::fill($category, $translations);
            $category->save();

            if ($image) {
                $category->addImage($image);
            }

            return $category;
        });
    }

    /**
     * سحب تصنيف في شجرة التصنيفات: اب جديد (null للجذر) وموضع بين اخوته، ثم يُعاد ترقيم ترتيب
     * الاخوة. الرقم (code) لا يتغير هنا؛ النقل باعادة الترقيم في شجرة الاصناف (ItemTreeService).
     */
    public function moveInTree(Category $category, ?int $parentId, int $position): Category
    {
        if ($parentId !== null) {
            $this->guardAgainstCycle($category, $parentId);
        }

        return DB::transaction(function () use ($category, $parentId, $position): Category {
            $category->parent_id = $parentId;
            $category->save();

            $this->resequence($parentId, (int) $category->id, $position);

            return $category;
        });
    }

    /**
     * تفعيل التصنيف او ايقافه.
     */
    public function toggleStatus(Category $category): Category
    {
        $category->status = $category->status === CategoryStatus::ACTIVE ? CategoryStatus::INACTIVE : CategoryStatus::ACTIVE;
        $category->save();

        return $category;
    }

    /**
     * التصنيف لا يصير ابناً لنفسه ولا لاحد فروعه، لان ذلك يقطع الشجرة.
     */
    public function guardAgainstCycle(Category $category, int $parentId): void
    {
        if ($parentId === (int) $category->id) {
            throw new DomainException(__('A category cannot be moved into itself.'));
        }

        $parents = $this->categories->query()->pluck('parent_id', 'id');
        $cursor = $parents[$parentId] ?? null;
        $visited = [];

        while ($cursor !== null && ! isset($visited[$cursor])) {
            if ((int) $cursor === (int) $category->id) {
                throw new DomainException(__('A category cannot be moved into one of its own subcategories.'));
            }

            $visited[$cursor] = true;
            $cursor = $parents[$cursor] ?? null;
        }
    }

    /**
     * اعادة ترقيم الاخوة بعد الادراج في الموضع المطلوب (الموضع خارج المدى يُقصر على الطرفين).
     */
    public function resequence(?int $parentId, int $movedId, int $position): void
    {
        $siblings = $this->categories->query()
            ->where('parent_id', $parentId)
            ->orderBy('sort_order')
            ->pluck('id')
            ->reject(fn ($id) => (int) $id === $movedId)
            ->values()
            ->all();

        array_splice($siblings, max(0, min($position, count($siblings))), 0, [$movedId]);

        foreach ($siblings as $order => $id) {
            $this->categories->query()->whereKey($id)->update(['sort_order' => $order]);
        }
    }

    /**
     * التصنيف الذي تحته تصنيفات او اصناف لا يُحذف (DomainException برسالة السبب).
     */
    public function delete(Category $category): void
    {
        $this->tree->removeCategory($category);
    }

    /**
     * يحذف ما يجوز حذفه ويترك الباقي، ويعيد عدد ما بقي. يُعاد المرور ما دام شيء يُحذف،
     * فتصنيف اب اختير مع كل فروعه يُحذف بعدها.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): int
    {
        $pending = $this->categories->query()->whereKey($ids)->get();

        do {
            $before = $pending->count();

            $pending = $pending->reject(function (Category $category): bool {
                try {
                    $this->tree->removeCategory($category);

                    return true;
                } catch (DomainException) {
                    return false;
                }
            });
        } while ($pending->isNotEmpty() && $pending->count() < $before);

        return $pending->count();
    }
}
