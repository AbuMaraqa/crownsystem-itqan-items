<?php

namespace Modules\Core\Support;

use Closure;
use Illuminate\Database\QueryException;
use Masmerise\Toaster\Toaster;

/**
 * حذف سجل من البيانات الاساسية: اذا كان مرتبطاً بسجلات اخرى ترفض قاعدة البيانات حذفه،
 * فتُعرض رسالة للمستخدم بدل صفحة الخطأ.
 */
class SafeDelete
{
    /**
     * صفحات Livewire: الرسالة تظهر عبر Toaster.
     */
    public static function run(Closure $delete): bool
    {
        if (self::attempt($delete)) {
            return true;
        }

        Toaster::error(self::message());

        return false;
    }

    /**
     * بلا واجهة: false اذا رفضت قاعدة البيانات الحذف لارتباط السجل. صفحات Inertia تعيد
     * self::message() في flash.error.
     */
    public static function attempt(Closure $delete): bool
    {
        try {
            $delete();

            return true;
        } catch (QueryException $exception) {
            // 23000: مخالفة قيد سلامة مرجعية.
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return false;
        }
    }

    public static function message(): string
    {
        return __('This record is linked to other records and cannot be deleted.');
    }
}
