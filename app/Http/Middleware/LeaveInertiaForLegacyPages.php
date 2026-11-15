<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * خلال الانتقال: رابط في لوحة Vue يفتح صفحة Livewire لم تُنقل بعد. Inertia ينتظر JSON، فنرد
 * بطلب تحميل كامل للصفحة (409 + X-Inertia-Location) بدل عرض HTML داخل نافذة خطأ.
 */
class LeaveInertiaForLegacyPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('X-Inertia')
            && $request->isMethod('GET')
            && ! $response->headers->has('X-Inertia')
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return Inertia::location($request->fullUrl());
        }

        return $response;
    }
}
