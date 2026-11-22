<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Enums\AccountType;
use Modules\Core\Entities\User;
use Modules\Core\Support\SafeDelete;

class AccountService
{
    /**
     * الادوار التي ندفع لها: حسابها تحت الذمم الدائنة (Core.supplier_account_parent).
     *
     * @var array<int, string>
     */
    public const PAYABLE_ROLES = ['Supplier', 'Delivery Company', 'Shipping Company', 'Insurance Company', 'Clearance Company', 'Service Provider'];

    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $account = Account::create($this->normalizeAccountData($data));
            $this->saveTranslation($account, $data);

            return $account->refresh();
        });
    }

    /**
     * Ensure a ledger account exists for a user based on the roles assigned to
     * them (Supplier -> Accounts Payable, Customer -> Accounts Receivable).
     * Called whenever a user is created/updated with one of these roles.
     *
     * @param  array<int, string>  $roleNames
     */
    public function syncForRoles(User $user, array $roleNames): void
    {
        // كل جهة ندفع لها (المورد، وشركات التوصيل والشحن والتأمين والتخليص، ومزود الخدمة) حسابها
        // تحت الذمم الدائنة.
        if (array_intersect(self::PAYABLE_ROLES, $roleNames) !== []) {
            $this->createForUser($user, 'supplier_account_parent');
        } elseif (in_array('Customer', $roleNames, true)) {
            $this->createForUser($user, 'customer_account_parent');
        } else {
            $this->createForUser($user);
        }
    }

    /**
     * Create (or return the existing) ledger account for a user, nested under
     * the parent account configured via the given "Core.{$parentKey}" account
     * mapping (defaults to "Core.user_account_parent" -> "Accounts Receivable" / 1130).
     */
    public function createForUser(User $user, string $parentKey = 'user_account_parent'): ?Account
    {
        // الحساب المحذوف يبقى حاجزاً user_id في الفهرس الفريد، فيُسترجع بدل انشاء حساب ثانٍ.
        $existing = $user->account ?? Account::withTrashed()->where('user_id', $user->id)->first();
        $parent = AccountMapping::query()
            ->where('source_module', 'Core')
            ->where('source_key', $parentKey)
            ->first()
            ?->account;

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            // صار للمستخدم دور يغيّر جهة حسابه (مثلاً اصبح شركة شحن) ولا قيود عليه بعد: ينتقل تحت
            // الحساب الاب الصحيح بنوعه وطبيعة رصيده. حساب عليه قيود يبقى كما هو.
            if ($parent && $existing->parent_id !== $parent->id && $parentKey !== 'user_account_parent' && ! $existing->hasJournalLines()) {
                $existing->update([
                    'code' => $this->nextChildCode($parent->id, $parent->type),
                    'parent_id' => $parent->id,
                    'type' => $parent->type->value(),
                    'normal_balance' => $parent->normal_balance->value(),
                ]);
            }

            return $existing;
        }

        if (! $parent) {
            return null;
        }

        return DB::transaction(function () use ($user, $parent) {
            $account = Account::create([
                'code' => $this->nextChildCode($parent->id, $parent->type),
                'parent_id' => $parent->id,
                'type' => $parent->type->value(),
                'normal_balance' => $parent->normal_balance->value(),
                'is_postable' => true,
                'is_active' => true,
                'created_by' => $user->id,
                'user_id' => $user->id,
            ]);

            $name = $user->name ?: $user->email;

            $account->translateOrNew('ar')->fill(['name' => $name]);
            $account->translateOrNew('en')->fill(['name' => $user->name_en ?: $name]);
            $account->save();

            return $account->refresh();
        });
    }

    public function update(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data) {
            $account->update(Arr::except($this->normalizeAccountData($data), ['created_by']));
            $this->saveTranslation($account, $data);

            return $account->refresh();
        });
    }

    /**
     * قيم نموذج الحساب الجديد: تحت حساب أب يأخذ الرقم التالي في فرعه، ونوعه وطبيعة رصيده.
     *
     * @return array<string, mixed>
     */
    public function newAccountDefaults(?int $parentId = null): array
    {
        $defaults = [
            'code' => '',
            'parent_id' => null,
            'type' => null,
            'normal_balance' => null,
            'is_active' => true,
            'is_postable' => false,
            'sort_order' => 0,
        ];

        $parent = $parentId ? Account::query()->find($parentId) : null;

        if ($parent) {
            $defaults['parent_id'] = $parent->id;
            $defaults['code'] = $this->nextChildCode($parent->id);
            $defaults['type'] = $parent->type?->value();
            $defaults['normal_balance'] = $parent->normal_balance?->value();
        }

        return $defaults;
    }

    /**
     * الحذف يرفضه النموذج (DomainException) ان كان للحساب فروع او قيود؛ وfalse ان كان
     * مرتبطاً بسجلات اخرى في قاعدة البيانات.
     */
    public function delete(Account $account): bool
    {
        return SafeDelete::attempt(fn () => $account->delete());
    }

    public function nextChildCode(?int $parentId, AccountType|int|string|null $type = null): string
    {
        $parent = $parentId ? Account::query()->find($parentId) : null;
        $prefix = $parent?->code;

        if (! $prefix) {
            $type = $type instanceof AccountType ? $type : AccountType::tryFrom((int) $type);

            return match ($type) {
                AccountType::ASSET => '1000',
                AccountType::LIABILITY => '2000',
                AccountType::EQUITY => '3000',
                AccountType::REVENUE => '4000',
                AccountType::EXPENSE => '5000',
                default => '1000',
            };
        }

        // الاطول اولاً: الترتيب النصي وحده يجعل 113099 بعد 1130100 فيتكرر الرقم. والمحذوف رقمه محجوز.
        $lastCode = Account::withTrashed()
            ->where('parent_id', $parent->id)
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        if (! $lastCode) {
            return $prefix.'01';
        }

        $suffixLength = max(strlen($lastCode) - strlen($prefix), 2);
        $number = (int) substr($lastCode, strlen($prefix));

        return $prefix.str_pad((string) ($number + 1), $suffixLength, '0', STR_PAD_LEFT);
    }

    private function normalizeAccountData(array $data): array
    {
        $type = $data['type'] ?? null;

        return [
            'code' => $data['code'],
            'parent_id' => $data['parent_id'] ?? null,
            'type' => (int) $type,
            'normal_balance' => (int) ($data['normal_balance'] ?? Account::normalBalanceForType($type)->value()),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_postable' => (bool) ($data['is_postable'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $data['created_by'] ?? auth()->id(),
        ];
    }

    private function saveTranslation(Account $account, array $data): void
    {
        $locale = $data['locale'] ?? app()->getLocale();

        $translation = $account->translateOrNew($locale);

        $translation->fill([
            'name' => $data['name'] ?? $account->name,
            'description' => $data['description'] ?? null,
        ]);

        $translation->created_by = $translation->created_by ?? ($data['created_by'] ?? auth()->id());

        $account->save();
    }
}
