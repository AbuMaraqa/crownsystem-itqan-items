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

    public function test_a_price_group_line_with_its_colours_is_accepted(): void
    {
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();

        ItemsGrid::ensureWeightLines([$this->groupLine($white, [[$w45, 2]], 16)]);

        $this->addToAssertionCount(1);
    }
}
