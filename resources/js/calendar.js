import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import arLocale from '@fullcalendar/core/locales/ar';

/**
 * هذا الملف يُحمَّل بعد @livewireScripts في القالب، اي بعد ان يكون Alpine قد اقلع
 * وقيّم x-data. لذلك لا نسجّل مكوّن Alpine هنا (سيصل متأخراً)، بل نتيح المكتبة
 * ونُعلم الصفحة بجاهزيتها، ويتولى @script داخل الودجت انشاء التقويم.
 */
window.CrownFullCalendar = {
    Calendar,
    plugins: [dayGridPlugin, listPlugin, interactionPlugin],
    locales: { ar: arLocale },
};

window.dispatchEvent(new CustomEvent('crown-fullcalendar-ready'));
