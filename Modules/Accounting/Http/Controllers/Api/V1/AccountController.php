<?php

namespace Modules\Accounting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Transformers\V1\AccountResource;
use Modules\Core\Traits\ApiResponse;
use Spatie\QueryBuilder\QueryBuilder;

class AccountController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $defaultPerPage = config('core.pagination.per_page', 10);
        $maxPerPage = config('core.pagination.max_per_page', 100);
        $perPage = min((int) request('per_page', $defaultPerPage), $maxPerPage);

        $accounts = QueryBuilder::for(Account::class)
            ->allowedFields(AccountResource::allowedFields())
            ->allowedFilters(AccountResource::allowedFilters())
            ->allowedSorts(AccountResource::allowedSorts())
            ->with(['translations', 'parent.translations'])
            ->defaultSort('code')
            ->paginate($perPage)
            ->appends(request()->query());

        return $this->successResponse(
            AccountResource::collection($accounts),
            __('Accounts retrieved successfully')
        );
    }

    public function tree()
    {
        return $this->successResponse(
            Account::getTreeData(),
            __('Chart of accounts retrieved successfully')
        );
    }

    public function show(Account $account)
    {
        $account->load(['translations', 'parent.translations', 'childrenRecursive']);

        return $this->successResponse(
            new AccountResource($account),
            __('Account retrieved successfully')
        );
    }
}
