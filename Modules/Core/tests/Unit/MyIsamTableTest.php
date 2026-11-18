<?php

namespace Modules\Core\tests\Unit;

use Modules\Core\Support\Itqan\ItqanSource;
use PHPUnit\Framework\TestCase;

/**
 * قارئ ملفات إتقان على قاعدة almamoun2015 الحقيقية، ان وُجدت على الجهاز (ITQAN_DATA_PATH).
 * طوبق القارئ مع tools/myisam.js صفاً صفاً في 12 جدولاً؛ هنا قيم ثابتة معروفة من تلك القاعدة.
 */
class MyIsamTableTest extends TestCase
{
    private ItqanSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('ITQAN_DATA_PATH') ?: 'C:\\laragon\\www\\Itqan\\almamoun2015';

        if (! is_file($path.DIRECTORY_SEPARATOR.'pricelists.frm')) {
            $this->markTestSkipped('Itqan data folder is not available on this machine.');
        }

        $this->source = new ItqanSource($path);
    }

    public function test_it_reads_a_small_packed_table_with_arabic_text(): void
    {
        $lists = iterator_to_array($this->source->rows('pricelists'), false);

        $this->assertCount(4, $lists);
        $this->assertSame(['PriceListId' => 1, 'PriceListString' => 'سعر الكاش', 'PriceListDesc' => '', 'PriceListType' => '2', 'isActive' => 'Y'], $lists[0]);
        $this->assertSame([1, 2, 3, 102], array_column($lists, 'PriceListId'));
    }

    public function test_it_reads_every_live_row_of_a_large_table(): void
    {
        $count = 0;

        foreach ($this->source->rows('itemsprices', ['PriceListId']) as $row) {
            $count++;
            $this->assertSame(['PriceListId'], array_keys($row));
        }

        $this->assertSame(21144, $count);
        $this->assertSame($count, $this->source->count('itemsprices'));
    }

    public function test_it_decodes_doubles_and_keeps_ids_as_text_with_leading_zeros(): void
    {
        $first = $this->source->rows('itemsprices')->current();

        $this->assertSame('00003', $first['ItemId']);
        $this->assertSame(21.63, $first['SellPrice']);
        $this->assertSame(17.4, $first['BuyPrice']);
    }

    public function test_it_reads_dates_and_currency_rates(): void
    {
        $currencies = iterator_to_array($this->source->rows('currency', ['CurrStr', 'CurrVal']), false);

        $this->assertSame(['CurrStr' => 'NIS', 'CurrVal' => 1.0], $currencies[0]);

        $opening = null;
        foreach ($this->source->rows('money', ['MoneyType', 'MoneyDate']) as $row) {
            if ($row['MoneyType'] === 'ImptMoney') {
                $opening = $row;

                break;
            }
        }

        $this->assertNotNull($opening);
        $this->assertStringStartsWith('2025-12-31', (string) $opening['MoneyDate']);
    }
}
