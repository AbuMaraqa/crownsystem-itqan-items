{{--
    جدول اصناف المستند بشكل شبكة الاصناف (order-items-grid): # | الصنف | باركود | اعمدة المستند.
    الاعمدة الاضافية في <x-slot:head> (th بالصنف: px-2 py-2.5 font-medium)، والصفوف في
    الفتحة الافتراضية: <x-accounting::items.row> و <x-accounting::items.sub-items>.
--}}
@props(['minWidth' => 'min-w-[52rem]'])

<div {{ $attributes->class(['overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10']) }}>
    <table class="w-full {{ $minWidth }} border-collapse text-sm">
        <thead>
            <tr class="bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <th class="w-10 px-2 py-2.5 text-center font-medium">#</th>
                <th class="px-2 py-2.5 text-start font-medium">{{ __('Item') }}</th>
                <th class="w-32 px-2 py-2.5 text-start font-medium">{{ __('Barcode') }}</th>
                {{ $head ?? '' }}
            </tr>
        </thead>
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
