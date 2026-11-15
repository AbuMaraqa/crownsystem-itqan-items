import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface Locale {
    code: string;
    native: string;
    flag: string;
    dir: 'rtl' | 'ltr';
    /** The current page in this language (App\Support\Locales::forPage). */
    url: string;
}

/** The site's languages, the current one, and the same page in another language. */
export function useLocales() {
    const page = usePage();

    const locales = computed(() => (page.props.locales ?? []) as Locale[]);
    const current = computed(() => locales.value.find((locale) => locale.code === page.props.locale));

    /** A full load: the texts and the direction change with it. */
    function urlFor(code: string): string {
        return locales.value.find((locale) => locale.code === code)?.url ?? page.url;
    }

    return { locales, current, urlFor };
}
