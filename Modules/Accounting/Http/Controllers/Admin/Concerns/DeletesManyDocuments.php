<?php

namespace Modules\Accounting\Http\Controllers\Admin\Concerns;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;

/**
 * الحذف الجماعي في قوائم المستندات المحاسبية (`<routes>.destroy-many`، `DataTable :bulk-delete-url`):
 * كل مستند يُحذف بخدمة حذفه المفرد نفسها، فتبقى قواعدها (المسودة فقط، غير المرتبط...) ورسائلها
 * وآثارها (القيد، اسطر المستند، حالة المستند الاصل). مستند محذوف سابقاً تعرضه القائمة (فلتر المحذوفات)
 * يُترك برسالة ولا يُحذف مرة اخرى.
 *
 * المتحكم يعرّف `bulkDeleteQuery()` (استعلام جدوله نفسه، مع `withTrashed()` حين تعرض القائمة
 * المحذوفات) و`deleteDocument()` (حذف الخدمة المفرد).
 */
trait DeletesManyDocuments
{
    use DeletesManyRecords;

    /**
     * The single delete of the document's service: a DomainException keeps the document.
     */
    abstract protected function deleteDocument(Model $document): void;

    protected function deleteRecord(Model $record): bool
    {
        if (method_exists($record, 'trashed') && $record->trashed()) {
            throw new DomainException(__('This document has already been deleted.'));
        }

        $this->deleteDocument($record);

        return true;
    }
}
