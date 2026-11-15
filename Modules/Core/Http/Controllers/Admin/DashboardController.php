<?php

namespace Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Core/Home/Index', $this->dashboardService->widgets($request->user()));
    }

    /**
     * احداث تقويم طلبيات الشراء التجميعية للمدى الظاهر في التقويم.
     */
    public function purchaseOrderEvents(Request $request): JsonResponse
    {
        $range = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        return response()->json([
            'data' => $this->dashboardService->purchaseOrderEvents(Carbon::parse($range['start']), Carbon::parse($range['end'])),
        ]);
    }
}
