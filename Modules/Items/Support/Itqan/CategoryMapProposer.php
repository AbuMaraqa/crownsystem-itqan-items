<?php

namespace Modules\Items\Support\Itqan;

/**
 * يقترح ربط كل عقدة تصنيف في إتقان بالشكل الجديد. في إتقان كان تصنيف الالمنيوم
 * «المنيوم <ماركة> <لون>» (مثل «المنيوم الوبين بدون دهان»)، فيصبح هنا تصنيف الماركة
 * «ألمنيوم/الوبين» ولونه «بدون دهان». الاقتراح يراجعه صاحب النظام في ملف CSV قبل
 * النقل الكامل. التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.1.
 */
final class CategoryMapProposer
{
    public const ALUMINUM_ROOT = 'ألمنيوم';

    /** رقم جذر شجرة الاصناف في إتقان. */
    public const TREE_ROOT = '0';

    /** الماركات والأنظمة كما تُكتب في أسماء إتقان بعد التوحيد. الأطول يُطابَق أولاً. */
    public const BRANDS = [
        'الوبين سيستم', 'الوبين', 'رويال سيستم', 'رويال', 'كليل سيستم', 'كليل', 'سبير', 'نابكو سيستم', 'نابكو',
        'متسبي', 'الوم جولد', 'جولد', 'بروفال', 'اكستال سيستم', 'اكستال كيرتن', 'اكستال', 'انتوب', 'SDR',
        'بدرية', 'الفايز', 'فايز', 'يوناني', 'مستر بروفيل', 'ايتان بزنوي', 'البجينو', 'افيزار', 'جولان تساخ',
        'الرحمن', 'ديلمار', 'مفرومال', 'شرمر', 'باركان', 'تركي', 'اردني', 'مستورد',
    ];

    /**
     * دهّانون ومورّدون، لا أنظمة: تصنيفاتهم في إتقان تحوي مقاطع أنظمة عدة. العقدة التي تبدأ
     * بقية اسمها بواحد منها تذهب الى «ألمنيوم» واسمها كاملاً لون (التصميم §10، R-4).
     */
    public const PAINTERS = ['دهان', 'بدون دهان شركة الرحمن'];

    /** كلمات تصنيفات ملغاة في إتقان. */
    public const JUNK = [
        'حذف', 'حدف', 'غلط', 'تعديل', 'احتياط', 'سكراب', '؟؟؟', 'RenameIt',
        'ليس للاستعمال', 'ليس للاستخدام', 'ليست للاستعمال', 'ليست للاستخدام',
    ];

    /** كلمات في اول اللون لا تميّزه. */
    private const FINISH_LEADING_WORDS = ['لون', 'دهان'];

    private const DEFAULT_FINISH = 'بدون دهان';

    /**
     * @param  array<int, array<string, mixed>>  $nodes  صف لكل عقدة تصنيف: ItemId, ItemString, ItemParent, isActive, items
     * @return array<string, CategoryMapEntry>  بمفتاح رقم العقدة القديمة
     */
    public function propose(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $id = trim((string) $node['ItemId']);

            // «0» جذر شجرة إتقان («شجرة الأصناف») وليس تصنيفاً؛ أبناؤه هم التصنيفات الجذرية.
            if ($id !== self::TREE_ROOT) {
                $byId[$id] = $node;
            }
        }

        $entries = [];
        $resolve = function (string $id) use (&$resolve, &$entries, $byId): CategoryMapEntry {
            if (isset($entries[$id])) {
                return $entries[$id];
            }

            $node = $byId[$id];
            $parentId = trim((string) ($node['ItemParent'] ?? '0'));
            $parent = ($parentId !== $id && isset($byId[$parentId])) ? $resolve($parentId) : null;

            return $entries[$id] = $parent ? $this->child($node, $parent) : $this->root($node);
        };

        foreach (array_keys($byId) as $id) {
            $resolve((string) $id);
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function root(array $node): CategoryMapEntry
    {
        $name = ItqanNames::normalize((string) $node['ItemString']);
        $remainder = ItqanNames::aluminumRemainder($name);

        if ($remainder === null) {
            return $this->entry($node, $name, null, str_contains($name, 'بالوزن') ? 'weight' : 'simple');
        }

        if ($this->startsWithAny($remainder, self::PAINTERS)) {
            return $this->entry($node, self::ALUMINUM_ROOT, $this->finish($remainder), 'weight', 'painter');
        }

        $brand = $this->brand($remainder);

        if ($brand === null) {
            return $this->entry($node, self::ALUMINUM_ROOT, $this->finish($remainder), 'weight', 'brand?');
        }

        return $this->entry(
            $node,
            self::ALUMINUM_ROOT.'/'.$brand,
            $this->finish(trim(mb_substr($remainder, mb_strlen($brand)))),
            'weight',
        );
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($text === $prefix || str_starts_with($text, $prefix.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function child(array $node, CategoryMapEntry $parent): CategoryMapEntry
    {
        $name = ItqanNames::normalize((string) $node['ItemString']);
        $underAluminum = $parent->finish !== null && str_starts_with($parent->categoryPath, self::ALUMINUM_ROOT);

        // «المنيوم نابكو ابيض» تحت «المنيوم نابكو» ماركة ولون، لا تصنيف فرعي (R-5).
        $entry = $underAluminum && ItqanNames::aluminumRemainder($name) !== null
            ? $this->root($node)
            : $this->entry($node, $parent->categoryPath.'/'.$name, $parent->finish, $parent->pricingType);

        if ($entry->kept() && ! $parent->kept()) {
            return new CategoryMapEntry(
                $entry->legacyCode, $entry->legacyName, $entry->items, CategoryMapEntry::SKIP,
                $entry->categoryPath, $entry->finish, $entry->pricingType, 'parent skipped',
            );
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function entry(array $node, string $path, ?string $finish, string $pricingType, string $note = ''): CategoryMapEntry
    {
        $reason = $this->skipReason($node);

        return new CategoryMapEntry(
            trim((string) $node['ItemId']),
            trim((string) $node['ItemString']),
            (int) ($node['items'] ?? 0),
            $reason === null ? CategoryMapEntry::KEEP : CategoryMapEntry::SKIP,
            $path,
            $finish,
            $pricingType,
            $reason ?? $note,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function skipReason(array $node): ?string
    {
        $name = ItqanNames::normalize((string) $node['ItemString']);

        if (strtoupper(trim((string) ($node['isActive'] ?? 'Y'))) === 'N') {
            return 'inactive';
        }

        if ($name === '' || preg_match('/^[0-9\s]+$/u', $name)) {
            return 'numeric name';
        }

        foreach (self::JUNK as $word) {
            if (str_contains($name, ItqanNames::normalize($word))) {
                return 'junk: '.$word;
            }
        }

        return null;
    }

    private function brand(string $remainder): ?string
    {
        $brands = self::BRANDS;
        usort($brands, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($brands as $brand) {
            if ($remainder === $brand || str_starts_with($remainder, $brand.' ')) {
                return $brand;
            }
        }

        return null;
    }

    private function finish(string $rest): string
    {
        foreach (self::FINISH_LEADING_WORDS as $word) {
            if (str_starts_with($rest, $word.' ')) {
                $rest = trim(mb_substr($rest, mb_strlen($word)));
            }
        }

        return $rest === '' ? self::DEFAULT_FINISH : $rest;
    }
}
