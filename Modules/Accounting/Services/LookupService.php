<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Core\Support\SafeDelete;

/**
 * كتابة جداول البيانات الاساسية البسيطة (بنود التكاليف، تصنيفات المرفقات، الضرائب...):
 * الاضافة باسم من اضافها، والتعديل، وتفعيل السجل وايقافه من الجدول، والحذف الآمن.
 * كل جدول يرث هذه الخدمة بمستودعه.
 */
abstract class LookupService
{
    public function __construct(protected readonly RepositoryInterface $records) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId): Model
    {
        return $this->records->create([...$data, 'created_by' => $userId]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        unset($data['created_by']);

        return $this->records->update($record, $data);
    }

    public function setActive(Model $record, bool $active): Model
    {
        return $this->records->update($record, ['is_active' => $active]);
    }

    /**
     * false اذا رفضت قاعدة البيانات الحذف لارتباط السجل بسجلات اخرى.
     */
    public function delete(Model $record): bool
    {
        return SafeDelete::attempt(fn () => $this->records->delete($record));
    }
}
