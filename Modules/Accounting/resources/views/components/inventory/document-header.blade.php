@props([
    'icon' => 'heroicon-o-document-text',
    'title',
    'reference' => null,
    'status' => null,
    'meta' => [],
])

@once
    <script>
        {{-- اختصارات المستند: Ctrl+S يحفظ المسودة، Ctrl+Enter يرحّل (ويبقى تأكيد الترحيل).
             الزر يُبحث عنه داخل مكوّن Livewire نفسه بـ wire:click (save / post)، فلا يعمل
             الاختصار الا حين يكون الزر ظاهراً ومتاحاً للمستخدم (صلاحيته وحالة المستند). --}}
        document.addEventListener('alpine:init', () => {
            Alpine.data('documentShortcuts', () => ({
                hints: [],

                init() {
                    this.refresh();
                    window.addEventListener('document-shortcuts-refresh', () => this.refresh());
                },

                button(action) {
                    const root = this.$root.closest('[wire\\:id]') ?? document;

                    return [...root.querySelectorAll(`[wire\\:click="${action}"]`)]
                        .find((element) => ! element.disabled && element.offsetParent !== null) ?? null;
                },

                refresh() {
                    this.hints = [['save', 'Ctrl+S'], ['post', 'Ctrl+Enter']]
                        .filter(([action]) => this.button(action))
                        .map(([action, keys]) => {
                            this.button(action).setAttribute('aria-keyshortcuts', keys.replace('Ctrl', 'Control'));

                            return { action, keys };
                        });
                },

                handle(event) {
                    // defaultPrevented: مفتاح عالجه حقل اولاً (Enter في خلايا جدول الاصناف) لا يُعاد تفسيره هنا.
                    if (event.defaultPrevented || ! (event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || event.isComposing) {
                        return;
                    }

                    const action = { s: 'save', enter: 'post' }[event.key.toLowerCase()];
                    const button = action ? this.button(action) : null;

                    if (! button) {
                        return;
                    }

                    event.preventDefault();
                    // يخرج التركيز من الحقل الحالي فتُرسل قيمته قبل الحفظ.
                    document.activeElement?.blur?.();
                    button.click();
                },
            }));
        });

        document.addEventListener('livewire:init', () => {
            Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => window.dispatchEvent(new CustomEvent('document-shortcuts-refresh')))));
        });
    </script>
@endonce

{{-- ترويسة المستند: هويته وحالته والاجراءات المتاحة عليه --}}
<div
    x-data="documentShortcuts"
    x-on:keydown.window="handle($event)"
    class="print:hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
>
    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                @svg($icon, 'h-6 w-6')
            </span>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h1>
                    @if ($reference)
                        <span class="font-mono text-lg font-semibold text-primary">{{ $reference }}</span>
                    @endif
                    @if ($status)
                        <x-accounting::inventory.status-badge :status="$status" />
                    @endif
                </div>

                @if (count($meta))
                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @foreach ($meta as $item)
                            @if (filled($item[1] ?? null))
                                <span class="inline-flex items-center gap-1">
                                    @svg($item[0], 'h-3.5 w-3.5')
                                    {{ $item[1] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div x-show="hints.length" x-cloak class="mt-1.5 hidden flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-500 sm:flex dark:text-gray-400">
                    <template x-for="hint in hints" :key="hint.action">
                        <span class="inline-flex items-center gap-1">
                            <kbd class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300" dir="ltr" x-text="hint.keys"></kbd>
                            <span x-text="hint.action === 'save' ? @js(__('Save')) : @js(__('Post'))"></span>
                        </span>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{ $slot }}
        </div>
    </div>
</div>
