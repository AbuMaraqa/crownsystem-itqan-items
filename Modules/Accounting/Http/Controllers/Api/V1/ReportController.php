<?php

namespace Modules\Accounting\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\Accounting\Services\AccountingReportService;
use Modules\Core\Traits\ApiResponse;

class ReportController extends Controller
{
    use ApiResponse;

    public function trialBalance(AccountingReportService $reports)
    {
        return $this->successResponse(
            $reports->trialBalance(request('from'), request('to'))->values(),
            __('Trial balance retrieved successfully')
        );
    }
}
