<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Repositories\Contracts\AccountRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class AccountRepository extends BaseEloquentRepository implements AccountRepositoryInterface
{
    public function __construct(Account $model)
    {
        parent::__construct($model);
    }

    public function forTree(): Collection
    {
        return $this->query()
            ->with('translations')
            ->withCount(['children as children_count' => fn ($query) => $query->reorder()])
            ->orderBy('code')
            ->get();
    }

    /**
     * رصيد كل حساب مع ارصدة ما تحته: رصيد الحساب المباشر يُضاف الى كل حساب في مساره
     * (path = /1/5/12/)، فلا يُقارن كل حساب بكل حساب.
     */
    public function rolledUpBalances(Collection $accounts): array
    {
        $direct = $this->directBalances();
        $balances = [];

        foreach ($accounts as $account) {
            $amount = $direct[(int) $account->id] ?? 0.0;

            if ($amount == 0.0) {
                continue;
            }

            foreach ($this->pathIds($account) as $id) {
                $balances[$id] = ($balances[$id] ?? 0.0) + $amount;
            }
        }

        return array_map(fn (float $balance) => round($balance, 4), $balances);
    }

    public function parentTree(): array
    {
        return Account::getTreeData();
    }

    public function selfAndDescendantIds(Account $account): array
    {
        return $this->query()
            ->where(fn ($query) => $query
                ->whereKey($account->id)
                ->orWhere('path', 'like', '%/'.$account->id.'/%'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findForForm(int $id): Account
    {
        /** @var Account */
        return $this->query()->with('translations')->findOrFail($id);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @return array<int, float>
     */
    private function directBalances(): array
    {
        $lineTable = (new JournalLine)->getTable();
        $entryTable = (new JournalEntry)->getTable();

        return JournalLine::query()
            ->select("{$lineTable}.account_id")
            ->selectRaw("SUM({$lineTable}.debit_base) as debit_base, SUM({$lineTable}.credit_base) as credit_base")
            ->join("{$entryTable} as posted_entries", 'posted_entries.id', '=', "{$lineTable}.journal_entry_id")
            ->whereNull('posted_entries.deleted_at')
            ->whereIn('posted_entries.status', [
                JournalEntryStatus::POSTED->value(),
                JournalEntryStatus::VOIDED->value(),
            ])
            ->groupBy("{$lineTable}.account_id")
            ->get()
            ->mapWithKeys(fn (JournalLine $line) => [
                (int) $line->account_id => round((float) $line->debit_base - (float) $line->credit_base, 4),
            ])
            ->all();
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
