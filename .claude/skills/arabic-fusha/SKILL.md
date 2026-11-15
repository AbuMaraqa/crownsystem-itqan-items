---
name: arabic-fusha
description: Use this skill whenever generating, editing, translating, or reviewing Arabic text. Enforce Modern Standard Arabic (الفصحى) and avoid dialects.
---

# Arabic Fusha Writing Rules

When writing Arabic content, ALWAYS use Modern Standard Arabic (العربية الفصحى).

## Mandatory Rules

- استخدم العربية الفصحى الحديثة فقط.
- لا تستخدم اللهجات العامية، بما فيها الفلسطينية أو المصرية أو الشامية أو الخليجية.
- لا تستخدم كلمات عامية مثل:
  - شو
  - وين
  - بدك
  - بدي
  - هاد
  - هاي
  - إيش
  - ليش
  - مش
  - مو
  - هلأ
  - خلينا
  - عشان

## Grammar

- استخدم تراكيب عربية سليمة وطبيعية.
- حافظ على الإعراب عندما يكون مناسبًا.
- استخدم علامات الترقيم العربية بشكل صحيح.
- استخدم الهمزات والتاء المربوطة والياء والألف المقصورة بشكل صحيح.
- لا تكتب العربية بطريقة مترجمة حرفيًا من الإنجليزية.

## UI / Software Content

When generating Arabic UI text:

- Prefer concise and professional Modern Standard Arabic.
- Use terminology appropriate for professional software.
- Keep button labels short.
- Use natural Arabic rather than literal translations.

Examples:

Bad:
"بدك تحذف المستخدم؟"

Good:
"هل تريد حذف المستخدم؟"

Bad:
"ما في بيانات"

Good:
"لا توجد بيانات."

Bad:
"اضغط هون"

Good:
"اضغط هنا."

## Restaurant System

When generating restaurant-related content:

- أسماء الأطباق باللغة العربية الفصحى.
- وصف الأطباق باللغة العربية الفصحى.
- التصنيفات باللغة العربية الفصحى.
- رسائل النظام باللغة العربية الفصحى.
- لا تستخدم أسلوبًا عاميًا في وصف الطعام.

Example:

Bad:
"جرب أطيب برغر عندنا!"

Good:
"استمتع ببرغر شهي محضّر من مكونات طازجة وبنكهة مميزة."

## Important

If the user writes in dialect, understand the dialect normally, but DO NOT imitate it in generated Arabic content.

The user's dialect is input language only. Generated Arabic content must remain in Modern Standard Arabic.

When uncertain between a colloquial expression and a formal expression, ALWAYS choose the formal expression.