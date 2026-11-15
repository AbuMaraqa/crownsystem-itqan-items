{{-- Modules/Core/Resources/views/components/activity-log-modal.blade.php --}}
@php
    use Modules\Core\Support\ActivityLogFormatter;
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900">{{ __('Activity Log') }}</h3>
        <span class="text-sm text-gray-500">{{ __('Activities Count') }} {{ $activities->count() }}</span>
    </div>

    @if($activities->count() > 0)
        <div class="max-h-96 overflow-y-auto space-y-3 pr-1">
            @foreach($activities as $activity)
                @php
                    $rows = ActivityLogFormatter::rows($activity);
                @endphp

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ ActivityLogFormatter::eventColor($activity) }}">
                                    {{ ActivityLogFormatter::eventLabel($activity) }}
                                </span>
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                                    {{ ActivityLogFormatter::subjectLabel($activity) }}
                                </span>
                            </div>

                            <div class="mt-3">
                                <div class="mb-1 text-xs font-medium text-gray-500">
                                    {{ __('By') }}
                                </div>

                                @if($activity->causer)
                                    @if($activity->causer instanceof \Modules\Core\Entities\User)
                                        {{ $activity->causer->user_display_html }}
                                    @else
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-600">
                                                {{ mb_strtoupper(mb_substr($activity->causer->name ?? __('Unknown'), 0, 1)) }}
                                            </div>
                                            <span class="font-medium text-gray-900">
                                                {{ $activity->causer->name ?? __('Unknown') }}
                                            </span>
                                        </div>
                                    @endif
                                @else
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-600">
                                            {{ mb_strtoupper(mb_substr(__('System'), 0, 1)) }}
                                        </div>
                                        <span class="font-medium text-gray-900">
                                            {{ __('System') }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 text-left">
                            <time class="text-sm text-gray-500">
                                {{ $activity->created_at->diffForHumans() }}
                            </time>
                            <br>
                            <span class="text-xs text-gray-400">
                                {{ $activity->created_at->format('Y-m-d H:i') }}
                            </span>
                        </div>
                    </div>

                    @if(count($rows) > 0)
                        <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700">
                                            {{ __('Field') }}
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700">
                                            {{ __('Old Value') }}
                                        </th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-700">
                                            {{ __('New Value') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach($rows as $row)
                                        <tr @class(['bg-amber-50/50' => $row['changed']])>
                                            <td class="w-1/4 px-3 py-2 font-medium text-gray-900">
                                                {{ $row['label'] }}
                                            </td>
                                            <td class="w-1/3 px-3 py-2 text-gray-600">
                                                <span class="inline-flex max-w-full rounded bg-red-50 px-2 py-1 text-red-700">
                                                    <span class="truncate">{{ $row['old'] }}</span>
                                                </span>
                                            </td>
                                            <td class="w-1/3 px-3 py-2 text-gray-600">
                                                <span class="inline-flex max-w-full rounded bg-emerald-50 px-2 py-1 text-emerald-700">
                                                    <span class="truncate">{{ $row['new'] }}</span>
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500">
                            {{ __('No changed fields recorded') }}
                        </p>
                    @endif

                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium text-blue-600">
                            {{ __('Technical Details') }}
                        </summary>
                        <pre class="mt-2 max-h-40 overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">{{ ActivityLogFormatter::rawProperties($activity) }}</pre>
                    </details>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-8 text-gray-500">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p class="mt-2">{{ __('No activities recorded') }}</p>
        </div>
    @endif
</div>
