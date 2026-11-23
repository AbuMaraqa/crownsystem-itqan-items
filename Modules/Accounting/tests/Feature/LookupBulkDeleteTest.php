<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Repositories\Contracts\TaxRepositoryInterface;
use Modules\Accounting\Services\TaxService;
use Modules\Core\Entities\User;
use Modules\Core\Support\SafeDelete;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * الحذف الجماعي في جداول البيانات الاساسية للمحاسبة (الضرائب وتصنيفات العملاء مثالاً): يُحذف المحدد
 * بقواعد الحذف المفرد نفسها، وما يرفضه الحذف المفرد يبقى، وبلا صلاحية الحذف يُرفض الطلب.
 */
class LookupBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'Tax View List', 'Tax Delete',
        'Customer Group View List', 'Customer Group Delete',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions = self::PERMISSIONS): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function tax(string $code): Tax
    {
        return Tax::query()->create(['name' => 'ضريبة '.$code, 'code' => $code, 'rate' => 16, 'is_active' => true]);
    }

    /**
     * The service refuses the given tax as if the database kept it for linked records.
     */
    private function refuseDeleting(Tax $linked): void
    {
        $this->app->bind(TaxService::class, fn ($app) => new class($app->make(TaxRepositoryInterface::class), (int) $linked->id) extends TaxService
        {
            public function __construct(TaxRepositoryInterface $records, private readonly int $linkedId)
            {
                parent::__construct($records);
            }

            public function delete(Model $record): bool
            {
                return (int) $record->getKey() === $this->linkedId ? false : parent::delete($record);
            }
        });
    }

    public function test_the_selected_taxes_are_deleted(): void
    {
        $this->signIn();
        [$first, $second, $kept] = [$this->tax('A'), $this->tax('B'), $this->tax('C')];

        $this->delete(route('accounting.taxes.destroy-many'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect()
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($kept);
    }

    public function test_a_tax_the_single_delete_refuses_stays(): void
    {
        $this->signIn();
        [$free, $linked] = [$this->tax('A'), $this->tax('B')];
        $this->refuseDeleting($linked);

        $this->delete(route('accounting.taxes.destroy', $linked->id))->assertSessionHas('error', SafeDelete::message());

        $this->delete(route('accounting.taxes.destroy-many'), ['ids' => [$free->id, $linked->id]])
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, SafeDelete::message()));

        $this->assertSoftDeleted($free);
        $this->assertNotSoftDeleted($linked);

        $this->delete(route('accounting.taxes.destroy-many'), ['ids' => [$linked->id]])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, SafeDelete::message()));

        $this->assertNotSoftDeleted($linked);
    }

    public function test_rows_outside_the_table_are_refused_whole(): void
    {
        $this->signIn();
        $tax = $this->tax('A');
        $gone = $this->tax('B');
        $gone->delete();

        $this->delete(route('accounting.taxes.destroy-many'), ['ids' => [$tax->id, $gone->id]])->assertSessionHasErrors('ids');

        $this->assertNotSoftDeleted($tax);
    }

    public function test_customer_groups_are_deleted_and_free_their_customers(): void
    {
        $this->signIn();
        Role::findOrCreate('Customer', 'web');

        $first = CustomerGroup::query()->create(['name' => 'تجزئة', 'profit_margin' => 10, 'is_active' => true]);
        $second = CustomerGroup::query()->create(['name' => 'جملة', 'profit_margin' => 5, 'is_active' => true]);
        $customer = User::factory()->create(['customer_group_id' => $first->id])->assignRole('Customer');

        $this->delete(route('accounting.customer-groups.destroy-many'), ['ids' => [$first->id, $second->id]])
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNull($customer->fresh()->customer_group_id);
    }

    public function test_bulk_delete_needs_the_delete_permission(): void
    {
        $tax = $this->tax('A');
        $group = CustomerGroup::query()->create(['name' => 'تجزئة', 'profit_margin' => 10, 'is_active' => true]);

        $this->signIn(['Tax View List', 'Customer Group View List']);

        $this->delete(route('accounting.taxes.destroy-many'), ['ids' => [$tax->id]])->assertForbidden();
        $this->delete(route('accounting.customer-groups.destroy-many'), ['ids' => [$group->id]])->assertForbidden();

        $this->assertNotSoftDeleted($tax);
        $this->assertNotSoftDeleted($group);
    }
}
