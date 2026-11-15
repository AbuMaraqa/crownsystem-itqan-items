<?php

namespace Modules\Accounting\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Services\AccountService;
use Tests\TestCase;

class AccountTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_next_child_code(): void
    {
        $service = app(AccountService::class);
        $parent = $service->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $this->assertSame('100001', $service->nextChildCode($parent->id));
    }

    public function test_next_child_code_continues_past_ninety_nine_children(): void
    {
        $service = app(AccountService::class);
        $parent = $service->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        foreach (['100099', '1000100'] as $code) {
            $service->create([
                'code' => $code,
                'name' => 'Customer '.$code,
                'parent_id' => $parent->id,
                'type' => AccountType::ASSET->value(),
                'is_postable' => true,
            ]);
        }

        $this->assertSame('1000101', $service->nextChildCode($parent->id));
    }

    public function test_next_child_code_skips_codes_of_deleted_accounts(): void
    {
        $service = app(AccountService::class);
        $parent = $service->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $service->create([
            'code' => '100001',
            'name' => 'Cash',
            'parent_id' => $parent->id,
            'type' => AccountType::ASSET->value(),
            'is_postable' => true,
        ])->delete();

        $this->assertSame('100002', $service->nextChildCode($parent->id));
    }

    public function test_it_prevents_cycles_in_the_account_tree(): void
    {
        $service = app(AccountService::class);
        $parent = $service->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $child = $service->create([
            'code' => '1100',
            'name' => 'Current Assets',
            'parent_id' => $parent->id,
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $this->expectException(DomainException::class);

        $parent->update(['parent_id' => $child->id]);
    }

    public function test_it_prevents_deleting_accounts_with_children(): void
    {
        $service = app(AccountService::class);
        $parent = $service->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $service->create([
            'code' => '1100',
            'name' => 'Cash',
            'parent_id' => $parent->id,
            'type' => AccountType::ASSET->value(),
            'is_postable' => true,
        ]);

        $this->expectException(DomainException::class);

        $parent->delete();
    }
}
