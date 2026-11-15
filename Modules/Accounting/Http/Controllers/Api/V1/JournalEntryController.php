<?php

namespace Modules\Accounting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Transformers\V1\JournalEntryResource;
use Modules\Core\Traits\ApiResponse;
use Spatie\QueryBuilder\QueryBuilder;

class JournalEntryController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $defaultPerPage = config('core.pagination.per_page', 10);
        $maxPerPage = config('core.pagination.max_per_page', 100);
        $perPage = min((int) request('per_page', $defaultPerPage), $maxPerPage);

        $entries = QueryBuilder::for(JournalEntry::class)
            ->allowedFields(JournalEntryResource::allowedFields())
            ->allowedFilters(JournalEntryResource::allowedFilters())
            ->allowedSorts(JournalEntryResource::allowedSorts())
            ->with(['lines.account.translations'])
            ->defaultSort('-entry_date')
            ->paginate($perPage)
            ->appends(request()->query());

        return $this->successResponse(
            JournalEntryResource::collection($entries),
            __('Journal entries retrieved successfully')
        );
    }

    public function show(JournalEntry $journalEntry)
    {
        $journalEntry->load(['lines.account.translations', 'lines.branch', 'lines.currency']);

        return $this->successResponse(
            new JournalEntryResource($journalEntry),
            __('Journal entry retrieved successfully')
        );
    }
}
