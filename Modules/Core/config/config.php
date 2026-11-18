<?php

return [
    'name' => 'Core',

    'pagination' => [
        'per_page' => 10,
        'max_per_page' => 100
    ],

    /*
     * الوان جاهزة للوحة التحكم في الاعدادات العامة؛ كلها داكنة بما يكفي لنص ابيض على الازرار.
     */
    'dashboard_color_presets' => ['#1570EF', '#C2410C', '#0E7490', '#047857', '#7C3AED', '#BE123C', '#334155'],

    /*
     * خطوط لوحة التحكم المتاحة في الاعدادات العامة. family هي قيمة family= في رابط Google Fonts css2.
     */
    'dashboard_fonts' => [
        'tajawal' => ['name' => 'Tajawal', 'family' => 'Tajawal:wght@400;500;700', 'stack' => "'Tajawal', system-ui, sans-serif"],
        'ibm-plex-sans-arabic' => ['name' => 'IBM Plex Sans Arabic', 'family' => 'IBM+Plex+Sans+Arabic:wght@400;500;700', 'stack' => "'IBM Plex Sans Arabic', system-ui, sans-serif"],
        'cairo' => ['name' => 'Cairo', 'family' => 'Cairo:wght@400;600;700', 'stack' => "'Cairo', system-ui, sans-serif"],
    ],

    /*
     * نقل بيانات برنامج إتقان: مجلد قاعدة almamoun2015 (ملفات MyISAM). يُمرَّر ايضاً بخيار --path.
     */
    'itqan' => [
        'data_path' => env('ITQAN_DATA_PATH'),
        // للـ ItqanImportSeeder: مراحل مفصولة بفاصلة (فارغ = كلها)، والتجربة، ومستخدم النسبة.
        'stages' => env('ITQAN_STAGES', ''),
        'dry_run' => env('ITQAN_DRY_RUN', false),
        'user_id' => env('ITQAN_USER_ID'),
    ],
];
