<?php

namespace Modules\Items\tests\Unit;

use Modules\Items\Support\Itqan\CategoryMapEntry;
use Modules\Items\Support\Itqan\CategoryMapProposer;
use Modules\Items\Support\Itqan\ItqanNames;
use PHPUnit\Framework\TestCase;

/**
 * اقتراح ربط تصنيفات إتقان: تصنيف «المنيوم <ماركة> <لون>» يصبح الماركة تصنيفاً
 * واللون لوناً. الأسماء من بيانات إتقان الفعلية.
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.1.
 */
class CategoryMapProposerTest extends TestCase
{
    public function test_normalize_unifies_hamza_and_spaces(): void
    {
        $this->assertSame('ثابت مجرين W45', ItqanNames::normalize('ثابت  مجرين W45 '));
        $this->assertSame('المنيوم رويال سيستم ميش', ItqanNames::normalize('ألمنيوم رويال سيستم ميش'));
        $this->assertSame('انبوب', ItqanNames::normalize('أنبوب'));
    }

    public function test_aluminum_root_splits_into_brand_and_finish(): void
    {
        $entry = $this->propose([$this->node('00009', 'المنيوم الوبين بدون دهان')])['00009'];

        $this->assertSame('keep', $entry->action);
        $this->assertSame('ألمنيوم/الوبين', $entry->categoryPath);
        $this->assertSame('بدون دهان', $entry->finish);
        $this->assertSame('weight', $entry->pricingType);
    }

    public function test_leading_colour_word_is_dropped_from_finish(): void
    {
        $this->assertSame('رال', $this->propose([$this->node('00007', 'المنيوم الوبين لون رال')])['00007']->finish);
    }

    public function test_longest_brand_wins(): void
    {
        $entry = $this->propose([$this->node('00349', 'المنيوم الوبين سيستم جلوسي')])['00349'];

        $this->assertSame('ألمنيوم/الوبين سيستم', $entry->categoryPath);
        $this->assertSame('جلوسي', $entry->finish);
    }

    public function test_hamza_spelling_of_aluminum_is_recognised(): void
    {
        $entry = $this->propose([$this->node('00031', 'ألمنيوم رويال سيستم ميش')])['00031'];

        $this->assertSame('ألمنيوم/رويال سيستم', $entry->categoryPath);
        $this->assertSame('ميش', $entry->finish);
    }

    public function test_unknown_brand_is_kept_and_flagged(): void
    {
        $entry = $this->propose([$this->node('00060', 'المنيوم  سابق 2')])['00060'];

        $this->assertSame('keep', $entry->action);
        $this->assertSame('brand?', $entry->note);
        $this->assertSame('ألمنيوم', $entry->categoryPath);
        $this->assertSame('سابق 2', $entry->finish);
    }

    public function test_painter_node_goes_to_aluminum_root_with_full_finish(): void
    {
        $entry = $this->propose([$this->node('00303', 'المنيوم دهان جلوسي اكسبرس لون ابيض')])['00303'];

        $this->assertSame('ألمنيوم', $entry->categoryPath);
        $this->assertSame('جلوسي اكسبرس لون ابيض', $entry->finish);
        $this->assertSame('painter', $entry->note);
        $this->assertSame('keep', $entry->action);
    }

    public function test_aluminum_named_subnode_resolves_like_a_root(): void
    {
        $map = $this->propose([
            $this->node('00023', 'المنيوم نابكو'),
            $this->node('00023001', 'المنيوم نابكو ابيض', parent: '00023'),
        ]);

        $this->assertSame('ألمنيوم/نابكو', $map['00023001']->categoryPath);
        $this->assertSame('ابيض', $map['00023001']->finish);
    }

    public function test_feminine_not_for_use_is_junk(): void
    {
        $map = $this->propose([
            $this->node('00023', 'المنيوم نابكو'),
            $this->node('00023013', 'ليست للاستعمال خدمة دهان نابكو ', parent: '00023'),
        ]);

        $this->assertSame('skip', $map['00023013']->action);
    }

    public function test_junk_and_numeric_and_inactive_are_skipped(): void
    {
        $map = $this->propose([
            $this->node('00299', 'حذفف'),
            $this->node('00228', '000000000'),
            $this->node('00243', '444 6665665666645563'),
            $this->node('00259', 'المنيوم سبير F40', isActive: 'N'),
        ]);

        foreach (['00299', '00228', '00243', '00259'] as $code) {
            $this->assertSame('skip', $map[$code]->action, $code);
            $this->assertNotSame('', $map[$code]->note, $code);
        }
    }

    public function test_non_aluminum_root_keeps_its_name_and_weight_keyword_sets_pricing(): void
    {
        $map = $this->propose([
            $this->node('00018', 'كوشوك تيرمو بالوزن'),
            $this->node('00034', 'سيلكون وماستك'),
        ]);

        $this->assertSame('كوشوك تيرمو بالوزن', $map['00018']->categoryPath);
        $this->assertNull($map['00018']->finish);
        $this->assertSame('weight', $map['00018']->pricingType);
        $this->assertSame('simple', $map['00034']->pricingType);
    }

    public function test_subcategory_is_placed_under_its_parent_path(): void
    {
        $map = $this->propose([
            $this->node('00020', 'قطع'),
            $this->node('000200349', 'قطع الوبين', parent: '00020'),
        ]);

        $this->assertSame('قطع/قطع الوبين', $map['000200349']->categoryPath);
        $this->assertSame('simple', $map['000200349']->pricingType);
        $this->assertNull($map['000200349']->finish);
    }

    public function test_itqan_tree_root_zero_is_not_a_category(): void
    {
        $map = $this->propose([
            $this->node('0', 'شجرة الأصناف', parent: '0'),
            $this->node('00009', 'المنيوم الوبين بدون دهان'),
        ]);

        $this->assertArrayNotHasKey('0', $map);
        $this->assertSame('ألمنيوم/الوبين', $map['00009']->categoryPath);
        $this->assertSame('بدون دهان', $map['00009']->finish);
    }

    public function test_child_of_skipped_parent_is_skipped(): void
    {
        $map = $this->propose([
            $this->node('00035', 'حذف'),
            $this->node('0003500001', 'فرع', parent: '00035'),
        ]);

        $this->assertSame('skip', $map['0003500001']->action);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, CategoryMapEntry>
     */
    private function propose(array $nodes): array
    {
        return (new CategoryMapProposer)->propose($nodes);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $id, string $name, string $parent = '0', string $isActive = 'Y', int $items = 10): array
    {
        return ['ItemId' => $id, 'ItemString' => $name, 'ItemParent' => $parent, 'isActive' => $isActive, 'items' => $items];
    }
}
