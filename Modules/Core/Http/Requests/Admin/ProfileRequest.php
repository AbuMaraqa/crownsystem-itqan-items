<?php

namespace Modules\Core\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * الملف الشخصي: الصورة الرمزية وصورة الغلاف فقط. الاسم والبريد يُداران من صفحة المستخدمين.
 */
class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // المستخدم يعدّل ملفه هو
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'cover_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'remove_avatar' => ['boolean'],
            'remove_cover_photo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => __('Avatar'),
            'cover_photo' => __('Cover Photo'),
        ];
    }
}
