<?php

namespace Modules\Items\tests\Unit;

use Modules\Items\Support\Itqan\CatalogSample;
use Modules\Items\Support\Itqan\CategoryMapEntry;
use PHPUnit\Framework\TestCase;

/**
 * العيّنة الصغيرة للتجربة: الوبين بأربعة ألوان (30 مقطعاً منها W45)، و20 إكسسواراً،
 * ومطاط واحد، وصنف من تصنيف ملغى. التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.3.
 */
class CatalogSampleTest extends TestCase
{
    public function test_sample_selects_w45_in_four_colours_and_fixed_extras(): void
    {
        $rows = [];
        foreach (['00009', '00001', '00002', '00043', '00003', '00018', '00035'] as $node) {
            $rows[] = $this->row($node, 'عقدة '.$node, '0', 'a_al');
        }
        $rows[] = $this->row('00020', 'قطع', '0', 'c_cont');
        $rows[] = $this->row('000200349', 'قطع الوبين', '00020', 'c_cont');

        for ($i = 1; $i <= 35; $i++) {
            $rows[] = $this->row('00009'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'مقطع '.$i, '00009');
        }
        foreach (['00001', '00002', '00043', '00003'] as $colour) {
            $rows[] = $this->row($colour.'001', 'مقطع  1', $colour);
            $rows[] = $this->row($colour.'099', 'مقطع غير مختار', $colour);
        }
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->row('000200349'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'اكسسوار '.$i, '000200349', 'a_storePicture');
        }
        $rows[] = $this->row('00018001', 'كوشوك 1', '00018');
        $rows[] = $this->row('00018002', 'كوشوك 2', '00018');
        $rows[] = $this->row('00035001', 'ملغى 1', '00035');
        $rows[] = $this->row('00035002', 'ملغى 2', '00035');

        $ids = array_column(CatalogSample::select($rows), 'ItemId');

        foreach (['00009001', '00001001', '00002001', '00043001', '00009030', '00018001', '00035001', '000200349020'] as $expected) {
            $this->assertContains($expected, $ids, $expected);
        }
        foreach (['00009031', '00003001', '00001099', '00018002', '00035002', '000200349021'] as $unexpected) {
            $this->assertNotContains($unexpected, $ids, $unexpected);
        }
        foreach (['00009', '00001', '00002', '00043', '00020', '000200349', '00018', '00035'] as $node) {
            $this->assertContains($node, $ids, 'node '.$node);
        }
        $this->assertNotContains('00003', $ids);
        $this->assertCount(30, array_filter($ids, fn (string $id): bool => str_starts_with($id, '00009') && strlen($id) === 8));
    }

    public function test_sample_keeps_only_the_map_entries_of_its_own_nodes(): void
    {
        $entry = fn (string $code): CategoryMapEntry => new CategoryMapEntry($code, $code, 1, 'keep', 'ألمنيوم/الوبين', 'ابيض', 'weight');
        $rows = [$this->row('00009', 'عقدة', '0', 'a_al'), $this->row('00009001', 'مقطع', '00009')];

        $map = CatalogSample::restrictMap(['00009' => $entry('00009'), '00003' => $entry('00003')], $rows);

        $this->assertSame(['00009'], array_keys($map));
    }

    /**
     * @return array<string, string>
     */
    private function row(string $id, string $name, string $parent, string $type = 'b_al'): array
    {
        return ['ItemId' => $id, 'ItemString' => $name, 'ItemParent' => $parent, 'ItemType' => $type, 'isActive' => 'Y'];
    }
}
