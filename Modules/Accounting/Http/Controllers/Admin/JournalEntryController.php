<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Http\Requests\Admin\JournalEntryRequest;
use Modules\Accounting\Repositories\Contracts\JournalEntryRepositoryInterface;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Entities\Currency;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;

/**
 * القيود اليومية اليدوية: مسودة تُعدَّل وتُحذف، ثم ترحيل، ثم الغاء بقيد عكسي.
 */
class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly JournalEntryService $journalEntryService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/JournalEntry/Index', [
            'table' => AdminTable::for($this->entries->tableQuery())
                ->search(['entry_number', 'reference_no'], null)
                ->filters(AdminFilter::exact('status'), AdminFilter::text('reference_no'), ...AdminFilter::dateRange('entry_date'))
                ->sorts('entry_number', 'entry_date', 'id')
                ->defaultSort('-entry_date')
                ->withCreator()
                ->rows(fn (JournalEntry $entry) => [
                    'id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'entry_date' => $entry->entry_date?->format('d/m/Y'),
                    'status' => StatusBadge::of($entry->status),
                    'reference_no' => $entry->reference_no,
                    'debit_total' => Number::money($entry->debit_total),
                    'credit_total' => Number::money($entry->credit_total),
                    'is_draft' => $entry->status === JournalEntryStatus::DRAFT,
                    'can_void' => $entry->canBeVoided(),
                ]),
            'statuses' => SelectOptions::from(JournalEntryStatus::options()),
        ]);
    }

    public function add(): Response
    {
        $currencyId = Currency::query()->where('is_default', true)->value('id') ?: Currency::query()->value('id');
        $line = ['account_id' => null, 'branch_id' => null, 'currency_id' => $currencyId, 'exchange_rate' => '1', 'debit' => '0', 'credit' => '0', 'description' => ''];

        return Inertia::render('Accounting/JournalEntry/Add', [
            'entry' => [
                'entry_date' => now()->toDateString(),
                'reference_no' => '',
                'description' => '',
                'lines' => [$line, $line],
            ],
            'defaultCurrencyId' => $currencyId,
            ...$this->formOptions(),
        ]);
    }

    public function store(JournalEntryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->journalEntryService->createDraft($data, $data['lines']);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.journal-entries.index')->with('success', __('Journal entry created successfully'));
    }

    /**
     * القيد بسطوره للقراءة. شاشات المستندات تربط بقيدها المرحّل عبر رابط التعديل، فيُحوَّل الى هنا.
     */
    public function show(int $journalEntry): Response
    {
        $entry = $this->entries->findWithLines($journalEntry);

        return Inertia::render('Accounting/JournalEntry/Show', [
            'entry' => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->format('d/m/Y'),
                'status' => StatusBadge::of($entry->status),
                'reference_no' => $entry->reference_no,
                'description' => $entry->description,
                'reversal_of' => $entry->reversalOf ? ['id' => $entry->reversalOf->id, 'entry_number' => $entry->reversalOf->entry_number] : null,
                'created_by' => $entry->createdBy?->name,
                'posted_by' => $entry->postedBy?->name,
                'posted_at' => $entry->posted_at?->format('d/m/Y H:i'),
                'is_draft' => $entry->status === JournalEntryStatus::DRAFT,
                'can_void' => $entry->canBeVoided(),
                'debit_total' => Number::money($entry->lines->sum('debit_base')),
                'credit_total' => Number::money($entry->lines->sum('credit_base')),
                'lines' => $entry->lines->map(fn (JournalLine $line) => [
                    'id' => $line->id,
                    'account' => trim($line->account?->code.' - '.$line->account?->name, ' -'),
                    'branch' => $line->branch?->name,
                    'currency' => $line->currency?->code,
                    'exchange_rate' => Number::clean($line->exchange_rate, 8),
                    'debit' => Number::money($line->debit),
                    'credit' => Number::money($line->credit),
                    'debit_base' => Number::money($line->debit_base),
                    'credit_base' => Number::money($line->credit_base),
                    'description' => $line->description,
                ])->values(),
            ],
        ]);
    }

    public function edit(int $journalEntry): Response|RedirectResponse
    {
        $entry = $this->entries->findWithLines($journalEntry);

        // المرحّل والملغى لا يُعدَّلان: يُعرضان للقراءة.
        if ($entry->status !== JournalEntryStatus::DRAFT) {
            return to_route('accounting.journal-entries.show', $entry->id);
        }

        return Inertia::render('Accounting/JournalEntry/Edit', [
            'entry' => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'reference_no' => (string) $entry->reference_no,
                'description' => (string) $entry->description,
                'lines' => $entry->lines->map(fn (JournalLine $line) => [
                    'account_id' => $line->account_id,
                    'branch_id' => $line->branch_id,
                    'currency_id' => $line->currency_id,
                    'exchange_rate' => Number::clean($line->exchange_rate, 8),
                    'debit' => Number::money($line->debit),
                    'credit' => Number::money($line->credit),
                    'description' => (string) $line->description,
                ])->values(),
            ],
            'defaultCurrencyId' => Currency::query()->where('is_default', true)->value('id') ?: Currency::query()->value('id'),
            ...$this->formOptions(),
        ]);
    }

    public function update(JournalEntryRequest $request, int $journalEntry): RedirectResponse
    {
        /** @var JournalEntry $entry */
        $entry = $this->entries->findOrFail($journalEntry);
        $data = $request->validated();

        try {
            $this->journalEntryService->updateDraft($entry, $data, $data['lines']);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.journal-entries.index')->with('success', __('Journal entry updated successfully'));
    }

    public function post(int $journalEntry): RedirectResponse
    {
        return $this->attempt(
            fn (JournalEntry $entry) => $this->journalEntryService->post($entry),
            $journalEntry,
            __('Journal entry posted successfully'),
        );
    }

    public function void(int $journalEntry): RedirectResponse
    {
        return $this->attempt(
            fn (JournalEntry $entry) => $this->journalEntryService->voidManual($entry),
            $journalEntry,
            __('Journal entry voided successfully'),
        );
    }

    public function destroy(int $journalEntry): RedirectResponse
    {
        return $this->attempt(
            fn (JournalEntry $entry) => $this->journalEntryService->delete($entry),
            $journalEntry,
            __('Journal entry deleted successfully'),
        );
    }

    public function info(int $journalEntry): JsonResponse
    {
        return response()->json(RecordInfo::of($this->entries->findOrFail($journalEntry)));
    }

    /**
     * @param  callable(JournalEntry): mixed  $action
     */
    private function attempt(callable $action, int $id, string $message): RedirectResponse
    {
        /** @var JournalEntry $entry */
        $entry = $this->entries->findOrFail($id);

        try {
            $action($entry);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'accounts' => SelectOptions::accounts(true),
            'branches' => SelectOptions::branches(),
            'currencies' => SelectOptions::currencies(),
        ];
    }
}
