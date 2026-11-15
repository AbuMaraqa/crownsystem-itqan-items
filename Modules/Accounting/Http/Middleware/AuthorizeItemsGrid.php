<?php

namespace Modules\Accounting\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Modules\Accounting\Support\ItemsGridDocuments;
use Symfony\Component\HttpFoundation\Response;

/**
 * صلاحية نقاط جدول الاصناف (items-grid/{document}/*): الجدول مشترك بين المستندات، فيُفتح لمن
 * يملك انشاء ذلك المستند او تعديله (ItemsGridDocuments::permissions). بديل `->can()` الذي يقبل
 * صلاحية واحدة فقط. الرفض AuthorizationException كما يرفض `->can()`، فيصل 403 لطلبات JSON ايضاً.
 */
class AuthorizeItemsGrid
{
    public function handle(Request $request, Closure $next): Response
    {
        $document = $request->route('document');

        if (! is_string($document)
            || ! ItemsGridDocuments::exists($document)
            || ! $request->user()?->canAny(ItemsGridDocuments::permissions($document))) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
