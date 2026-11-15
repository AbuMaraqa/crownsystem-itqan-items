<?php

namespace Modules\Accounting\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نقطة يحتاجها نموذج يُفتح للانشاء وللتعديل معاً (بحث، اقتراحات، معاينة حساب): تُفتح لمن يملك
 * احدى الصلاحيات المعطاة. بديل `->can()` الذي يقبل صلاحية واحدة فقط، والرفض
 * AuthorizationException كما يرفض `->can()`.
 *
 *   ->middleware(AuthorizeAnyPermission::class.':Production Order Create,Production Order Update')
 */
class AuthorizeAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($permissions === [] || ! $request->user()?->canAny($permissions)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
