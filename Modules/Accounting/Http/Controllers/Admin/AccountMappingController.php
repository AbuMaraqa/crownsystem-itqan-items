<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Http\Requests\Admin\AccountMappingRequest;
use Modules\Accounting\Repositories\Contracts\AccountMappingRepositoryInterface;
use Modules\Accounting\Services\AccountMappingService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * ربط الحسابات: المفاتيح ثابتة، والتعديل يغيّر الحساب المربوط في نافذة على صفحة القائمة.
 */
class AccountMappingController extends Controller
{
    public function __construct(
        private readonly AccountMappingRepositoryInterface $mappings,
        private readonly AccountMappingService $mappingService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/AccountMapping/Index', [
            'table' => AdminTable::for($this->mappings->tableQuery())
                ->search(['source_module', 'source_key'], translated: null)
                ->filters(
                    AdminFilter::exact('source_module'),
                    AllowedFilter::callback('mapped', function (Builder $query, mixed $value): void {
                        filter_var($value, FILTER_VALIDATE_BOOLEAN) ? $query->whereNotNull('account_id') : $query->whereNull('account_id');
                    }),
                )
                ->sorts('source_module', 'source_key', 'id')
                ->defaultSort('source_module')
                ->withCreator()
                ->rows(fn (AccountMapping $mapping) => [
                    'id' => $mapping->id,
                    'source_module' => $mapping->source_module,
                    'source_key' => $mapping->source_key,
                    'account_id' => $mapping->account_id ? (int) $mapping->account_id : null,
                    'account' => $mapping->account ? $mapping->account->code.' - '.$mapping->account->name : null,
                ]),
            'modules' => array_map(fn (string $module) => ['value' => $module, 'label' => $module], $this->mappings->sourceModules()),
            'accounts' => SelectOptions::accounts(true),
        ]);
    }

    public function update(AccountMappingRequest $request, int $mapping): RedirectResponse
    {
        /** @var AccountMapping $model */
        $model = $this->mappings->findOrFail($mapping);

        $accountId = $request->validated('account_id');
        $this->mappingService->setAccount($model, $accountId !== null ? (int) $accountId : null);

        return back()->with('success', __('Account mapping updated successfully'));
    }

    public function info(int $mapping): JsonResponse
    {
        return response()->json(RecordInfo::of($this->mappings->findOrFail($mapping)));
    }
}
