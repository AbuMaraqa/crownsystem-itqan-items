<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

/**
 * القراءات المشتركة بين سندات القبض والصرف (نفس الاعمدة ونفس الصفحات).
 */
interface VoucherRepositoryInterface extends RepositoryInterface
{
    /**
     * جدول السندات: الطرف وصورته، والحساب، والعملة، والقيد.
     */
    public function tableQuery(): Builder;

    /**
     * سند واحد لصفحته (التعديل، او العرض بعد الترحيل) مع الشيك والقيد.
     */
    public function findForPage(int $id): Model;
}
