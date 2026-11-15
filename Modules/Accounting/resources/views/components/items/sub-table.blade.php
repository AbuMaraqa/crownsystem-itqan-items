{{--
    الجدول المضغوط داخل <x-accounting::items.sub-items>: # | الصنف | باركود ثم اعمدة <x-slot:head>
    (th بالصنف: px-2 py-1 font-medium). <x-slot:lead> عمود قبل # (مثل التأشير). الصفوف: <x-accounting::items.sub-row>.
--}}
@props(['minWidth' => 'min-w-[36rem]'])

<div {{ $attributes->class(['overflow-x-auto']) }}>
    <table class="w-full {{ $minWidth }} border-collapse text-xs">
        <thead>
            <tr class="text-gray-500 dark:text-gray-400">
                {{ $lead ?? '' }}
                <th class="w-12 px-2 py-1 text-center font-medium">#</th>
                <th class="px-2 py-1 text-start font-medium">{{ __('Item') }}</th>
                <th class="w-28 px-2 py-1 text-start font-medium">{{ __('Barcode') }}</th>
                {{ $head ?? '' }}
            </tr>
        </thead>
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
