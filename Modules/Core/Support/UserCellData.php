<?php

namespace Modules\Core\Support;

use Modules\Core\Entities\User;

/**
 * ما يرسله الخادم لمكوّن UserCell في جداول Vue (الوجه الآخر لـ user_display_html):
 * الاسم والصورة وسطر ثانٍ (البريد او الهاتف). null اذا لم يكن هناك مستخدم.
 * يحتاج الاستعلام الى تحميل `<relation>.media` مسبقاً.
 */
final class UserCellData
{
    /**
     * @return array{id: int, name: string, avatar: string, detail: ?string}|null
     */
    public static function of(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'avatar' => $user->avatar_url,
            'detail' => $user->email ?: ($user->phone ?? null),
        ];
    }
}
