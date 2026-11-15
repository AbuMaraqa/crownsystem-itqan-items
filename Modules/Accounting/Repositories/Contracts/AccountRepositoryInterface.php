<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\Account;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface AccountRepositoryInterface extends RepositoryInterface
{
    /**
     * Every account of the chart with its translations and how many children it has, by code.
     *
     * @return Collection<int, Account>
     */
    public function forTree(): Collection;

    /**
     * The posted (and voided) balance of each account in the base currency, debit minus credit,
     * with the balances of its descendants added (a group shows the total of its branch).
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, float>
     */
    public function rolledUpBalances(Collection $accounts): array;

    /**
     * The chart as `{id, label, children}` nodes for the parent account choice.
     *
     * @return list<array<string, mixed>>
     */
    public function parentTree(): array;

    /**
     * The account and all accounts under it: none of them can become its parent.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(Account $account): array;

    public function findForForm(int $id): Account;

    public function count(): int;
}
