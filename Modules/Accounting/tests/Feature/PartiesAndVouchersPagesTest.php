<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحات الموردين والمستودعات وسندات القبض والصرف في لوحة Vue: القوائم والاضافة والتعديل،
 * وترحيل السندات والغاؤها وحذفها، وكل اجراء بصلاحيته.
 */
class PartiesAndVouchersPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = [
        'Supplier' => ['View List', 'View', 'Create', 'Update'],
        'Warehouse' => ['View List', 'View', 'Info', 'Create', 'Update', 'Delete'],
        'Receipt Voucher' => ['View List', 'View', 'Info', 'Create', 'Update', 'Delete', 'Post', 'Cancel'],
        'Payment Voucher' => ['View List', 'View', 'Info', 'Create', 'Update', 'Delete', 'Post', 'Cancel'],
    ];

    private Currency $currency;

    private User $customer;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach ([...$this->permissions(), 'Branch Create'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->signIn();

        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $year = FiscalYear::create([
            'name' => 'FY Test',
            'starts_at' => now()->startOfYear()->toDateString(),
            'ends_at' => now()->endOfYear()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
            'is_default' => true,
        ]);

        AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'name' => now()->format('F Y'),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
        ]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);

        $this->customer = User::factory()->create(['name' => 'زبون']);
        $this->customer->assignRole(Role::findOrCreate('Customer', 'web'));
        $this->supplier = User::factory()->create(['name' => 'مورد']);
        $this->supplier->assignRole(Role::findOrCreate('Supplier', 'web'));
    }

    /**
     * @return list<string>
     */
    private function permissions(?string $only = null): array
    {
        $names = [];

        foreach (self::ENTITIES as $entity => $actions) {
            if ($only === null || $only === $entity) {
                foreach ($actions as $action) {
                    $names[] = "{$entity} {$action}";
                }
            }
        }

        return $names;
    }

    private function signIn(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?? $this->permissions());
        $this->actingAs($user);

        return $user;
    }

    private function cashAccountId(): int
    {
        return (int) Account::query()->where('code', '1110')->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function voucherPayload(int $clientId, array $overrides = []): array
    {
        return [
            'client_id' => $clientId,
            'branch_id' => null,
            'account_id' => $this->cashAccountId(),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => 50,
            'notes' => null,
            ...$overrides,
        ];
    }

    private function draftReceipt(array $overrides = []): ReceiptVoucher
    {
        return app(ReceiptVoucherService::class)->create($this->voucherPayload($this->customer->id, $overrides));
    }

    // ---------------------------------------------------------------- suppliers

    public function test_the_supplier_list_shows_suppliers_only(): void
    {
        $this->get(route('accounting.suppliers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Supplier/Index')
                ->has('table.data', 1)
                ->where('table.data.0.name', 'مورد')
                ->has('table.data.0.avatar'));

        $this->get(route('accounting.suppliers.add'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Accounting/Supplier/Add'));
    }

    public function test_a_supplier_is_added_without_an_email_with_its_ledger_account(): void
    {
        $this->post(route('accounting.suppliers.store'), ['name' => 'باسل عبيدو', 'email' => '', 'tax_number' => '123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounting.suppliers.index'));

        $supplier = User::where('name', 'باسل عبيدو')->firstOrFail();
        $this->assertNull($supplier->email);
        $this->assertSame('123', $supplier->tax_number);
        $this->assertTrue($supplier->hasRole('Supplier'));
        $this->assertNotNull(Account::query()->where('user_id', $supplier->id)->first());
    }

    public function test_a_supplier_is_updated_and_a_used_email_is_refused(): void
    {
        $this->get(route('accounting.suppliers.edit', $this->supplier->id))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/Supplier/Edit')->where('supplier.name', 'مورد'));

        $this->put(route('accounting.suppliers.update', $this->supplier->id), ['name' => 'مورد معدل', 'email' => $this->customer->email])
            ->assertSessionHasErrors('email');

        $this->put(route('accounting.suppliers.update', $this->supplier->id), ['name' => 'مورد معدل', 'address' => 'رام الله'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounting.suppliers.index'));

        $this->assertSame('مورد معدل', $this->supplier->refresh()->name);
        $this->assertSame('رام الله', $this->supplier->address);
    }

    public function test_a_user_who_is_not_a_supplier_is_not_opened_as_one(): void
    {
        $this->get(route('accounting.suppliers.edit', $this->customer->id))->assertNotFound();
    }

    public function test_supplier_pages_need_their_permissions(): void
    {
        $this->signIn(['Supplier View List']);

        $this->get(route('accounting.suppliers.index'))->assertOk();
        $this->get(route('accounting.suppliers.add'))->assertForbidden();
        $this->post(route('accounting.suppliers.store'), ['name' => 'ممنوع'])->assertForbidden();
        $this->put(route('accounting.suppliers.update', $this->supplier->id), ['name' => 'ممنوع'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['name' => 'ممنوع']);
    }

    // ---------------------------------------------------------------- warehouses

    public function test_a_warehouse_is_added_listed_and_updated(): void
    {
        $this->post(route('accounting.warehouses.store'), ['name' => 'المخزن الرئيسي', 'code' => 'WH1', 'is_active' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounting.warehouses.index'));

        $warehouse = Warehouse::query()->where('code', 'WH1')->firstOrFail();
        $this->assertSame('المخزن الرئيسي', $warehouse->translate('ar')->name);

        $this->get(route('accounting.warehouses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Warehouse/Index')
                ->where('table.data.0.name', 'المخزن الرئيسي')
                ->where('table.data.0.inventory_account', null)
                ->has('branchOptions'));

        $this->get(route('accounting.warehouses.edit', $warehouse->id))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/Warehouse/Edit')->where('warehouse.code', 'WH1')->has('branchForm'));

        $this->post(route('accounting.warehouses.store'), ['name' => 'مخزن ثان', 'code' => 'WH1', 'is_active' => true])
            ->assertSessionHasErrors('code');

        $this->put(route('accounting.warehouses.update', $warehouse->id), ['name' => 'المخزن المركزي', 'code' => 'WH1', 'is_active' => false])
            ->assertSessionHasNoErrors();

        $warehouse->refresh();
        $this->assertSame('المخزن المركزي', $warehouse->translate('ar')->name);
        $this->assertFalse($warehouse->is_active);

        $this->getJson(route('accounting.warehouses.info', $warehouse->id))->assertOk()->assertJsonStructure(['created_at', 'activities']);
    }

    public function test_warehouses_are_deleted_one_or_many(): void
    {
        $first = Warehouse::create(['code' => 'A1', 'is_active' => true]);
        $second = Warehouse::create(['code' => 'A2', 'is_active' => true]);
        $third = Warehouse::create(['code' => 'A3', 'is_active' => true]);

        $this->delete(route('accounting.warehouses.destroy', $first->id))->assertSessionHas('success');
        $this->delete(route('accounting.warehouses.destroy-many'), ['ids' => [$second->id, $third->id]])
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertSame(0, Warehouse::query()->whereIn('id', [$first->id, $second->id, $third->id])->count());
    }

    public function test_warehouse_actions_need_their_permissions(): void
    {
        $warehouse = Warehouse::create(['code' => 'B1', 'is_active' => true]);
        $this->signIn(['Warehouse View List']);

        $this->get(route('accounting.warehouses.index'))->assertOk();
        $this->post(route('accounting.warehouses.store'), ['name' => 'x', 'is_active' => true])->assertForbidden();
        $this->delete(route('accounting.warehouses.destroy', $warehouse->id))->assertForbidden();
        $this->post(route('accounting.warehouses.branches.store'), ['name' => 'فرع'])->assertForbidden();
        $this->assertNotNull($warehouse->fresh());
    }

    // ---------------------------------------------------------------- vouchers

    public function test_the_voucher_lists_and_forms_are_inertia_pages(): void
    {
        $this->draftReceipt();

        $this->get(route('accounting.receipt-vouchers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ReceiptVoucher/Index')
                ->has('table.data', 1)
                ->where('table.data.0.client.name', 'زبون')
                ->where('table.data.0.editable', true)
                ->has('statusOptions'));

        $this->get(route('accounting.payment-vouchers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Accounting/PaymentVoucher/Index')->has('table.data', 0));

        $this->get(route('accounting.receipt-vouchers.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ReceiptVoucher/Add')
                ->where('voucher.currency_id', $this->currency->id)
                ->where('voucher.exchange_rate', '1.00000000')
                ->has('accountOptions'));

        $this->getJson(route('accounting.receipt-vouchers.parties', ['q' => 'زب']))->assertOk()->assertJsonFragment(['value' => $this->customer->id, 'label' => 'زبون']);
        $this->getJson(route('accounting.receipt-vouchers.exchange-rate', ['currency_id' => $this->currency->id, 'date' => now()->toDateString()]))->assertOk()->assertJson(['rate' => '1.00000000']);
    }

    public function test_a_receipt_voucher_is_added_updated_and_posted(): void
    {
        $this->post(route('accounting.receipt-vouchers.store'), $this->voucherPayload($this->customer->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $voucher = ReceiptVoucher::query()->latest('id')->firstOrFail();
        $this->assertSame(ReceiptVoucherStatus::DRAFT, $voucher->status);

        $this->get(route('accounting.receipt-vouchers.edit', $voucher->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ReceiptVoucher/Edit')
                ->where('record.editable', true)
                ->where('record.can_cancel', false)
                ->where('record.client.label', 'زبون'));

        $this->put(route('accounting.receipt-vouchers.update', $voucher->id), $this->voucherPayload($this->customer->id, ['amount' => 75]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertEquals(75, (float) $voucher->refresh()->amount);

        // زر الترحيل يحفظ النموذج اولاً ثم يرحّل.
        $this->post(route('accounting.receipt-vouchers.post', $voucher->id), $this->voucherPayload($this->customer->id, ['amount' => 80]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $voucher->refresh();
        $this->assertSame(ReceiptVoucherStatus::POSTED, $voucher->status);
        $this->assertEquals(80, (float) $voucher->amount);
        $this->assertSame(JournalEntryStatus::POSTED, $voucher->journalEntry->status);

        // بعد الترحيل لا يُعدَّل ولا يُحذف.
        $this->put(route('accounting.receipt-vouchers.update', $voucher->id), $this->voucherPayload($this->customer->id, ['amount' => 1]))->assertSessionHas('error');
        $this->delete(route('accounting.receipt-vouchers.destroy', $voucher->id))->assertSessionHas('error');
        $this->assertEquals(80, (float) $voucher->refresh()->amount);
        $this->assertNotNull($voucher->fresh());
    }

    public function test_a_cheque_voucher_needs_its_cheque_fields(): void
    {
        $this->post(route('accounting.receipt-vouchers.store'), $this->voucherPayload($this->customer->id, [
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
        ]))->assertSessionHasErrors(['cheque_number', 'cheque_date']);

        $this->assertSame(0, ReceiptVoucher::query()->count());
    }

    public function test_a_payment_voucher_is_added_and_posted_to_the_supplier(): void
    {
        $this->post(route('accounting.payment-vouchers.store'), $this->voucherPayload($this->supplier->id, ['amount' => 120]))
            ->assertSessionHasNoErrors();

        $voucher = PaymentVoucher::query()->latest('id')->firstOrFail();
        $this->assertStringStartsWith('PMT', $voucher->reference_no);

        $this->post(route('accounting.payment-vouchers.post', $voucher->id), $this->voucherPayload($this->supplier->id, ['amount' => 120]))
            ->assertSessionHas('success');

        $this->assertSame(PaymentVoucherStatus::POSTED, $voucher->refresh()->status);
    }

    public function test_a_posted_cash_voucher_is_cancelled_with_its_reason_but_not_one_with_an_open_cheque(): void
    {
        $service = app(ReceiptVoucherService::class);
        $cash = $service->post($this->draftReceipt());

        $this->get(route('accounting.receipt-vouchers.edit', $cash->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.editable', false)->where('record.can_cancel', true));

        $this->post(route('accounting.receipt-vouchers.cancel', $cash->id), ['reason' => 'خطأ في المبلغ'])->assertSessionHas('success');

        $cash->refresh();
        $this->assertSame(ReceiptVoucherStatus::CANCELLED, $cash->status);
        $this->assertSame('خطأ في المبلغ', $cash->cancellation_reason);

        $cheque = $service->post($this->draftReceipt([
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'CHQ-9',
            'cheque_date' => now()->toDateString(),
        ]));

        $this->get(route('accounting.receipt-vouchers.edit', $cheque->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.can_cancel', false)->has('record.cheque.label'));

        // الخدمة نفسها ترفض: الشيك القائم يُلغى من سجل الشيكات.
        $this->post(route('accounting.receipt-vouchers.cancel', $cheque->id))->assertSessionHas('error');
        $this->assertSame(ReceiptVoucherStatus::POSTED, $cheque->refresh()->status);
    }

    public function test_a_draft_voucher_is_deleted(): void
    {
        $voucher = $this->draftReceipt();

        $this->delete(route('accounting.receipt-vouchers.destroy', $voucher->id))->assertSessionHas('success');

        $this->assertNull(ReceiptVoucher::query()->find($voucher->id));
        $this->assertNotNull(ReceiptVoucher::withTrashed()->find($voucher->id));
    }

    public function test_voucher_actions_need_their_permissions(): void
    {
        $draft = $this->draftReceipt();
        $posted = app(ReceiptVoucherService::class)->post($this->draftReceipt());
        $this->signIn(['Receipt Voucher View List', 'Receipt Voucher Update']);

        $this->get(route('accounting.receipt-vouchers.index'))->assertOk();
        $this->get(route('accounting.receipt-vouchers.add'))->assertForbidden();
        $this->post(route('accounting.receipt-vouchers.post', $draft->id), $this->voucherPayload($this->customer->id))->assertForbidden();
        $this->post(route('accounting.receipt-vouchers.cancel', $posted->id))->assertForbidden();
        $this->delete(route('accounting.receipt-vouchers.destroy', $draft->id))->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('accounting.receipt-vouchers.info', $draft->id))->assertOk();

        $this->assertSame(ReceiptVoucherStatus::DRAFT, $draft->refresh()->status);
        $this->assertSame(ReceiptVoucherStatus::POSTED, $posted->refresh()->status);
    }
}
