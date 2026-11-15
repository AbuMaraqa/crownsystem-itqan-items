<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\NormalBalance;

/**
 * شجرة دليل الحسابات للعرض: تطابق الفلاتر (بحث، نوع، طرفي فقط، فعّال فقط)، ويُعرض مع كل
 * حساب مطابق مساره كاملاً حتى الجذر، ورصيد كل حساب يشمل ما تحته.
 */
final class AccountTree
{
    /**
     * @param  array{search?: string|null, type?: int|string|null, postable?: bool, active?: bool}  $filters
     */
    public function __construct(private readonly array $filters) {}

    /**
     * فلتر يضيّق الشجرة (البحث او النوع او الطرفي) يفتح كل فروعها.
     */
    public function narrows(): bool
    {
        return trim((string) ($this->filters['search'] ?? '')) !== ''
            || filled($this->filters['type'] ?? null)
            || (bool) ($this->filters['postable'] ?? false);
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, float>  $balances
     * @return array{nodes: list<array<string, mixed>>, visible: int}
     */
    public function build(Collection $accounts, array $balances): array
    {
        $matched = $accounts->filter(fn (Account $account) => $this->matches($account));
        $visibleIds = [];

        foreach ($matched as $account) {
            foreach ($this->pathIds($account) as $id) {
                $visibleIds[$id] = true;
            }
        }

        $visible = $accounts->filter(fn (Account $account) => isset($visibleIds[(int) $account->id]));
        $childrenByParent = $visible->groupBy(fn (Account $account) => (int) ($account->parent_id ?: 0));

        return [
            'nodes' => $this->level($childrenByParent, 0, $balances),
            'visible' => $visible->count(),
        ];
    }

    private function matches(Account $account): bool
    {
        if (($this->filters['active'] ?? false) && ! $account->is_active) {
            return false;
        }

        if (($this->filters['postable'] ?? false) && ! $account->is_postable) {
            return false;
        }

        if (filled($this->filters['type'] ?? null) && $account->type?->value() !== (int) $this->filters['type']) {
            return false;
        }

        $search = trim((string) ($this->filters['search'] ?? ''));

        if ($search === '') {
            return true;
        }

        $needle = Str::lower($search);

        return str_contains(Str::lower((string) $account->code), $needle)
            || str_contains(Str::lower((string) $account->name), $needle)
            || str_contains(Str::lower((string) $account->description), $needle)
            || $account->translations->contains(fn ($translation) => str_contains(Str::lower((string) $translation->name), $needle)
                || str_contains(Str::lower((string) $translation->description), $needle));
    }

    /**
     * @param  Collection<int, Collection<int, Account>>  $childrenByParent
     * @param  array<int, float>  $balances
     * @return list<array<string, mixed>>
     */
    private function level(Collection $childrenByParent, int $parentId, array $balances): array
    {
        return $childrenByParent->get($parentId, collect())
            ->sortBy('code', SORT_NATURAL)
            ->map(fn (Account $account) => $this->node($account, $childrenByParent, $balances))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Collection<int, Account>>  $childrenByParent
     * @param  array<int, float>  $balances
     * @return array<string, mixed>
     */
    private function node(Account $account, Collection $childrenByParent, array $balances): array
    {
        $balance = round((float) ($balances[(int) $account->id] ?? 0), 4);
        $direction = abs($balance) < 0.0001 ? null : ($balance > 0 ? NormalBalance::DEBIT : NormalBalance::CREDIT);

        return [
            'id' => (int) $account->id,
            'code' => $account->code,
            'name' => (string) $account->name,
            'description' => $account->description ? Str::limit((string) $account->description, 120) : null,
            'level' => (int) $account->level,
            'type' => StatusBadge::of($account->type),
            'normal_balance' => StatusBadge::of($account->normal_balance),
            'balance' => Number::money(abs($balance)),
            'balance_direction' => StatusBadge::of($direction),
            'is_postable' => (bool) $account->is_postable,
            'is_active' => (bool) $account->is_active,
            'has_children' => (int) $account->children_count > 0,
            'children' => $this->level($childrenByParent, (int) $account->id, $balances),
        ];
    }

    /**
     * @return list<int>
     */
    private function pathIds(Account $account): array
    {
        $ids = array_map('intval', array_filter(explode('/', trim((string) $account->path, '/'))));
        $ids[] = (int) $account->id;

        return array_values(array_unique($ids));
    }
}
