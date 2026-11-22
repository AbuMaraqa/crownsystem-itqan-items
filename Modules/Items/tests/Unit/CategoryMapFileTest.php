<?php

namespace Modules\Items\tests\Unit;

use Modules\Items\Support\Itqan\CategoryMapEntry;
use Modules\Items\Support\Itqan\CategoryMapFile;
use Modules\Items\Support\Itqan\ItqanCategoryNodes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ملف ربط التصنيفات يراجعه صاحب النظام في Excel ثم يُقرأ في النقل، فيجب ان يعود
 * كما كُتب، وان يرفض القيم الخاطئة بدل ان ينقل بها.
 */
class CategoryMapFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'category-map-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_round_trip_keeps_entries(): void
    {
        $entries = [
            '00009' => new CategoryMapEntry('00009', 'المنيوم الوبين بدون دهان', 666, 'keep', 'ألمنيوم/الوبين', 'بدون دهان', 'weight'),
            '00034' => new CategoryMapEntry('00034', 'سيلكون, وماستك', 27, 'keep', 'سيلكون وماستك', null, 'simple'),
            '00035' => new CategoryMapEntry('00035', 'حذف', 13, 'skip', 'حذف', null, 'simple', 'junk: حذف'),
        ];

        CategoryMapFile::write($this->path, $entries);

        $this->assertSame("\xEF\xBB\xBF", substr((string) file_get_contents($this->path), 0, 3));
        $this->assertEquals($entries, CategoryMapFile::read($this->path));
    }

    public function test_read_rejects_unknown_action(): void
    {
        file_put_contents($this->path, "legacy_code,legacy_name,items,action,category_path,finish,pricing_type,note\n00009,x,1,maybe,a,,weight,\n");

        $this->expectException(RuntimeException::class);
        CategoryMapFile::read($this->path);
    }

    public function test_read_rejects_unknown_pricing_type(): void
    {
        file_put_contents($this->path, "legacy_code,legacy_name,items,action,category_path,finish,pricing_type,note\n00009,x,1,keep,a,,kg,\n");

        $this->expectException(RuntimeException::class);
        CategoryMapFile::read($this->path);
    }

    public function test_read_rejects_missing_column(): void
    {
        file_put_contents($this->path, "legacy_code,legacy_name,items,action\n00009,x,1,keep\n");

        $this->expectException(RuntimeException::class);
        CategoryMapFile::read($this->path);
    }

    public function test_read_rejects_code_without_leading_zeros(): void
    {
        file_put_contents($this->path, "legacy_code,legacy_name,items,action,category_path,finish,pricing_type,note\n9,x,1,keep,a,,weight,\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Excel');
        CategoryMapFile::read($this->path);
    }

    public function test_read_rejects_invalid_utf8(): void
    {
        file_put_contents($this->path, "legacy_code,legacy_name,items,action,category_path,finish,pricing_type,note\n00009,\xC7\xE1\xE6\xC8\xED\xE4,1,keep,a,,weight,\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');
        CategoryMapFile::read($this->path);
    }

    public function test_assert_matches_rejects_mostly_unmapped_leaves(): void
    {
        $map = ['00009' => new CategoryMapEntry('00009', 'x', 1, 'keep', 'ألمنيوم/الوبين', 'بدون دهان', 'weight')];

        $this->expectException(RuntimeException::class);
        CategoryMapFile::assertMatches($map, ['00009' => [], '00001' => []], ['00009', '00001', '00001', '00001']);
    }

    public function test_assert_matches_rejects_codes_that_match_no_node(): void
    {
        $map = ['00099' => new CategoryMapEntry('00099', 'x', 1, 'keep', 'ألمنيوم/الوبين', 'بدون دهان', 'weight')];

        $this->expectException(RuntimeException::class);
        CategoryMapFile::assertMatches($map, ['00009' => []], ['00009']);
    }

    public function test_assert_matches_accepts_a_matching_map(): void
    {
        $map = ['00009' => new CategoryMapEntry('00009', 'x', 1, 'keep', 'ألمنيوم/الوبين', 'بدون دهان', 'weight')];

        CategoryMapFile::assertMatches($map, ['00009' => [], '00001' => []], ['00009', '00009', '00009', '00009', '00001']);
        $this->addToAssertionCount(1);
    }

    public function test_nodes_count_direct_leaves(): void
    {
        $nodes = ItqanCategoryNodes::fromRows([
            ['ItemId' => '00009', 'ItemString' => 'المنيوم الوبين بدون دهان', 'ItemParent' => '0', 'isActive' => 'Y'],
            ['ItemId' => '00009001', 'ItemString' => 'ثابت مجرين W45', 'ItemParent' => '00009', 'isActive' => 'Y'],
            ['ItemId' => '00009002', 'ItemString' => 'يو 3سم', 'ItemParent' => '00009', 'isActive' => 'Y'],
        ]);

        $this->assertSame(['00009'], array_keys($nodes));
        $this->assertSame(2, $nodes['00009']['items']);
        $this->assertSame('المنيوم الوبين بدون دهان', $nodes['00009']['ItemString']);
    }

    public function test_empty_container_types_are_categories(): void
    {
        $nodes = ItqanCategoryNodes::fromRows([
            ['ItemId' => '00014', 'ItemString' => 'بودرة دهان الوبين', 'ItemParent' => '0', 'ItemType' => 'a_al', 'isActive' => 'Y'],
            ['ItemId' => '000200573', 'ItemString' => 'اكسسوارات وبراغي', 'ItemParent' => '00020', 'ItemType' => 'c_cont', 'isActive' => 'Y'],
            ['ItemId' => '00020001', 'ItemString' => 'برغي', 'ItemParent' => '00020', 'ItemType' => 'a_storePicture', 'isActive' => 'Y'],
        ]);

        $this->assertArrayHasKey('00014', $nodes);
        $this->assertSame(0, $nodes['00014']['items']);
        $this->assertArrayHasKey('000200573', $nodes);
        $this->assertArrayNotHasKey('00020001', $nodes);
        $this->assertFalse(ItqanCategoryNodes::isCategory('00020001', 'a_storePicture', []));
        $this->assertTrue(ItqanCategoryNodes::isCategory('00014', 'a_al', []));
    }
}
