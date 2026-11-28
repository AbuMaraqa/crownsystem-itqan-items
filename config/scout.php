<?php

/*
 * محرك البحث (Laravel Scout + Meilisearch).
 *
 * الجداول المفهرسة يسجّلها كل موديول في مزوّده (SearchIndex::register)، وإعدادات كل
 * فهرس تأتي من الموديل نفسه (searchIndexSettings)، فلا تُكتب هنا.
 * بلا SCOUT_DRIVER (او null) يبقى البحث على قاعدة البيانات كما كان، وكذلك إن توقفت
 * خدمة Meilisearch: البحث يعود الى قاعدة البيانات والحفظ لا يفشل
 * (Modules\Core\Support\Search\SearchIndex).
 */
return [

    // meilisearch على السيرفر، null لتعطيل المحرك، collection للاختبارات.
    'driver' => env('SCOUT_DRIVER', 'null'),

    // بادئة اسماء الفهارس، ليشترك اكثر من نظام (او بيئة) في خادم Meilisearch واحد.
    'prefix' => env('SCOUT_PREFIX', ''),

    // true: تحديث الفهرس في طابور (queue:work)، false: في آخر الطلب نفسه.
    'queue' => env('SCOUT_QUEUE', false),

    // لا يُرسل السجل الى الفهرس قبل اعتماد المعاملة، فلا يُفهرس ما تراجعت عنه.
    'after_commit' => true,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    // المحذوف حذفاً ناعماً يبقى في الفهرس معلَّماً، فتجده القوائم التي تعرض المحذوفات.
    'soft_delete' => true,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),

        // مهلة الاتصال والطلب بالثواني: خادم متوقف لا يؤخر الحفظ ولا البحث.
        'connect_timeout' => (float) env('MEILISEARCH_CONNECT_TIMEOUT', 1),
        'timeout' => (float) env('MEILISEARCH_TIMEOUT', 5),

        // تُملأ من SearchIndex::settings() عند تشغيل اوامر artisan (scout:sync-index-settings).
        'index-settings' => [],
    ],

];
