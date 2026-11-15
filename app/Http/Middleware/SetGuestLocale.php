<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetGuestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('laravellocalization.supportedLocales', []));

        if ($request->query('lang') && in_array($request->query('lang'), $supported, true)) {
            session(['locale' => $request->query('lang')]);
        }

        app()->setLocale(session('locale', config('app.locale')));

        return $next($request);
    }
}
