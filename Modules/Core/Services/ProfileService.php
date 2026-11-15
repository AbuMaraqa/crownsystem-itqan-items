<?php

namespace Modules\Core\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Core\Entities\User;

class ProfileService
{
    /**
     * الصورة الرمزية وصورة الغلاف. الاسم والبريد لا يُعدَّلان من هنا.
     *
     * @param  array<string, mixed>  $data
     * @param  array{avatar?: UploadedFile|null, cover_photo?: UploadedFile|null}  $files
     */
    public function update(User $user, array $data, array $files): User
    {
        return DB::transaction(function () use ($user, $data, $files): User {
            foreach (['avatar', 'cover_photo'] as $collection) {
                if ($files[$collection] ?? null) {
                    $user->addMedia($files[$collection])->toMediaCollection($collection, 'media');
                } elseif ($data['remove_'.$collection] ?? false) {
                    $user->clearMediaCollection($collection);
                }
            }

            return $user;
        });
    }

    /**
     * يُشفَّر عبر cast النموذج (hashed).
     */
    public function changePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
    }
}
