<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['Account View List', 'Journal Entry View List', 'Accounting Report View List'];

    public function test_accounting_api_exposes_accounts_tree_and_trial_balance(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($user);

        app(AccountService::class)->create([
            'code' => '1000',
            'name' => 'Assets',
            'type' => AccountType::ASSET->value(),
            'is_postable' => false,
        ]);

        $this->getJson('/api/v1/accounting/accounts?filter[code]=1000', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/accounting/accounts/tree', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/accounting/reports/trial-balance', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    public function test_accounting_api_is_forbidden_without_permissions(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/accounting/accounts')->assertForbidden();
        $this->getJson('/api/v1/accounting/accounts/tree')->assertForbidden();
        $this->getJson('/api/v1/accounting/journal-entries')->assertForbidden();
        $this->getJson('/api/v1/accounting/reports/trial-balance')->assertForbidden();
    }
}
