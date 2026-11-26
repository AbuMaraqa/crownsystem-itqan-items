<?php

namespace Modules\Accounting\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Tests\TestCase;

/**
 * بلا صنف رئيسي وهمي: القطع (ألوان المقاطع) لا تكون الا داخل سطر مجموعة سعر (التصنيف × اللون).
 * سطر صنف يحمل قطعاً يُرفض برسالة واضحة، وسطر المجموعة بقطع ألوانها يُقبل.
 */
class GroupLinesOnlyTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    public function test_a_line_with_pieces_but_no_price_group_is_refused(): void
    {
        ['whiteW45' => $w45, 'whiteW60' => $w60] = $this->alpineGroups();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(__('Sub-items belong to a price group line; pick the colour group first.'));

        ItemsGrid::ensureWeightLines([[
            'product_id' => $w60->id,
            'pieces' => [['product_id' => $w45->id, 'piece_count' => 2, 'piece_length' => 6.5, 'weight_per_meter' => 1.25]],
        ]]);
    }

    /**
     * لون مقطع وحده كسطر صنف يضيع قيمته (الكيلو وقيمته على مجموعته)، فيُطلب ادخاله في سطر مجموعته.
     */
    public function test_a_colour_alone_as_a_line_is_sent_to_its_group_line(): void
    {
        $w45 = $this->alpineGroups()['whiteW45'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(__(':item is a colour of a price group; add it inside the line of its group.', ['item' => $w45->name]));

        ItemsGrid::ensureWeightLines([['product_id' => $w45->id, 'quantity' => 90, 'unit_price' => 3]]);
    }

    public function test_a_price_group_line_with_its_colours_is_accepted(): void
    {
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();

        ItemsGrid::ensureWeightLines([$this->groupLine($white, [[$w45, 2]], 16)]);

        $this->addToAssertionCount(1);
    }
}
