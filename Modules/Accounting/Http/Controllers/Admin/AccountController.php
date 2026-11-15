<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Http\Requests\Admin\AccountRequest;
use Modules\Accounting\Repositories\Contracts\AccountRepositoryInterface;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Support\AccountTree;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\SafeDelete;
use Modules\Items\Support\RecordInfo;

/**
 * دليل الحسابات: شجرة الحسابات بارصدتها، واضافة حساب (او حساب فرعي) وتعديله وحذفه.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly AccountService $accountService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->input('filter.search', '')),
            'type' => $request->input('filter.type') ?: null,
            'postable' => $request->boolean('filter.postable'),
            // «الفعّالة فقط» مفعّل افتراضياً كما في الصفحة السابقة.
            'active' => $request->has('filter.active') ? $request->boolean('filter.active') : true,
        ];

        $accounts = $this->accounts->forTree();
        $tree = new AccountTree($filters);
        $built = $tree->build($accounts, $this->accounts->rolledUpBalances($accounts));

        return Inertia::render('Accounting/Account/Index', [
            'tree' => $built['nodes'],
            'visibleCount' => $built['visible'],
            'totalCount' => $accounts->count(),
            'expandAll' => $tree->narrows(),
            'filters' => [
                'search' => $filters['search'],
                'type' => $filters['type'] !== null ? (int) $filters['type'] : null,
                'postable' => $filters['postable'],
                'active' => $filters['active'],
            ],
            'types' => SelectOptions::from(AccountType::options()),
        ]);
    }

    public function add(Request $request): Response
    {
        return Inertia::render('Accounting/Account/Add', [
            'account' => $this->accountService->newAccountDefaults($request->integer('parent_id') ?: null),
            ...$this->formOptions(),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        try {
            $this->accountService->create([...$request->validated(), 'created_by' => $request->user()->id]);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.accounts.index')->with('success', __('Account created successfully'));
    }

    public function edit(int $account): Response
    {
        $model = $this->accounts->findForForm($account);

        return Inertia::render('Accounting/Account/Edit', [
            'account' => [
                'id' => $model->id,
                'code' => $model->code,
                'name' => (string) $model->name,
                'description' => (string) $model->description,
                'parent_id' => $model->parent_id,
                'type' => $model->type?->value(),
                'normal_balance' => $model->normal_balance?->value(),
                'sort_order' => (int) $model->sort_order,
                'is_postable' => (bool) $model->is_postable,
                'is_active' => (bool) $model->is_active,
            ],
            'disabledParents' => $this->accounts->selfAndDescendantIds($model),
            ...$this->formOptions(),
        ]);
    }

    public function update(AccountRequest $request, int $account): RedirectResponse
    {
        /** @var Account $model */
        $model = $this->accounts->findOrFail($account);

        try {
            $this->accountService->update($model, $request->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.accounts.index')->with('success', __('Account updated successfully'));
    }

    public function destroy(int $account): RedirectResponse
    {
        /** @var Account $model */
        $model = $this->accounts->findOrFail($account);

        try {
            if (! $this->accountService->delete($model)) {
                return back()->with('error', SafeDelete::message());
            }
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Account deleted successfully'));
    }

    public function info(int $account): JsonResponse
    {
        return response()->json(RecordInfo::of($this->accounts->findOrFail($account)));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $normalBalances = [];

        foreach (AccountType::cases() as $type) {
            $normalBalances[$type->value()] = Account::normalBalanceForType($type)->value();
        }

        return [
            'parents' => $this->accounts->parentTree(),
            'types' => SelectOptions::from(AccountType::options()),
            'normalBalances' => SelectOptions::from(NormalBalance::options()),
            // طبيعة الرصيد التي تتبع كل نوع، فيضبطها النموذج عند تغيير النوع.
            'normalBalanceByType' => $normalBalances,
        ];
    }
}
