<?php

namespace Modules\Core\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * حذف سجل من البيانات الاساسية: اذا كان مرتبطاً بسجلات اخرى ترفض قاعدة البيانات حذفه،
 * فتُعرض رسالة للمستخدم بدل صفحة الخطأ.
 */
class SafeDelete
{
    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط السجل. صفحات Inertia تعيد
     * self::message() في flash.error.
     */
    public static function attempt(Closure $delete): bool
    {
        try {
            // داخل معاملة (نقطة حفظ إن كانت هناك معاملة أعلى): على PostgreSQL يُبطل
            // الخطأ المعاملة كلها، فالتراجع إلى نقطة الحفظ يُبقي ما بعده صالحاً.
            DB::transaction(fn () => $delete());

            return true;
        } catch (QueryException $exception) {
            if (! self::isLinked($exception)) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * مخالفة قيد مرجعي: 23000 في MySQL/SQLite، و23503 في PostgreSQL.
     */
    public static function isLinked(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23503'], true);
    }

    public static function message(): string
    {
        return __('This record is linked to other records and cannot be deleted.');
    }
}
