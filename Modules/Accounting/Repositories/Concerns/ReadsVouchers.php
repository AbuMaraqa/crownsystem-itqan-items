<?php

namespace Modules\Accounting\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * قراءات سندات القبض والصرف: الجدولان متطابقان، فتُكتب مرة واحدة.
 */
trait ReadsVouchers
{
    public function tableQuery(): Builder
    {
        return $this->query()->with(['client.media', 'account.translations', 'currency', 'journalEntry']);
    }

    public function findForPage(int $id): Model
    {
        return $this->query()
            ->with(['client', 'account.translations', 'currency', 'journalEntry', 'cheque'])
            ->findOrFail($id);
    }
}
