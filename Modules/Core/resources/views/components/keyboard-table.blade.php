@props([
    // تعريف الاعمدة: key, label, type (text|number|select|readonly), step, width, align, options, compute
    'columns' => [],

    // مسار خاصية Livewire التي تُخزَّن فيها الصفوف، مثل: data.items
    'statePath' => null,

    // الصفوف الابتدائية (تُقرأ من statePath تلقائياً عند اغفالها).
    'rows' => null,

    // قالب الصف الجديد.
    'newRow' => [],

    // انشاء صف جديد عند Enter في آخر خلية من آخر صف.
    'autoAppend' => true,

    // ابقاء صف فارغ واحد على الاقل.
    'minRows' => 1,

    'deletable' => true,
    'showFooter' => false,
])

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('keyboardTable', (config) => ({
                rows: [],
                columns: [],
                config: {},
                uid: 0,
                computers: {},
                syncTimer: null,

                init() {
                    this.config = config;
                    this.columns = config.columns || [];

                    // تجهيز دوال الاعمدة المحسوبة مرة واحدة بدل تفسيرها لكل خلية.
                    this.columns.forEach((column) => {
                        if (column.compute) {
                            this.computers[column.key] = new Function('row', 'index', `return (${column.compute});`);
                        }
                    });

                    this.rows = (config.rows || []).map((row) => this.hydrate(row));

                    while (this.rows.length < (config.minRows || 0)) {
                        this.rows.push(this.blankRow());
                    }
                },

                hydrate(row) {
                    // __uid يثبّت هوية الصف حتى لا يعيد المتصفح استخدام الحقول بعد الحذف.
                    return { __uid: ++this.uid, ...row };
                },

                blankRow() {
                    return this.hydrate(JSON.parse(JSON.stringify(this.config.newRow || {})));
                },

                value(row, column, index) {
                    if (this.computers[column.key]) {
                        const computed = this.computers[column.key](row, index);

                        return Number.isFinite(computed) ? computed : 0;
                    }

                    return row[column.key];
                },

                display(row, column, index) {
                    const raw = this.value(row, column, index);

                    if (column.type === 'number' || this.computers[column.key]) {
                        const decimals = column.decimals ?? 3;

                        return Number(raw || 0).toLocaleString('en-US', {
                            minimumFractionDigits: decimals,
                            maximumFractionDigits: decimals,
                        });
                    }

                    return raw ?? '';
                },

                editable(column) {
                    return column.type !== 'readonly' && ! this.computers[column.key];
                },

                editableColumns() {
                    return this.columns.filter((column) => this.editable(column));
                },

                /* ------------------------- التنقل ------------------------- */

                cell(rowIndex, colIndex) {
                    return this.$el.querySelector(
                        `[data-cell][data-row="${rowIndex}"][data-col="${colIndex}"]`
                    );
                },

                focusCell(rowIndex, colIndex) {
                    this.$nextTick(() => {
                        const target = this.cell(rowIndex, colIndex);

                        if (! target) {
                            return;
                        }

                        target.focus();

                        // تحديد المحتوى يجعل الكتابة تستبدل القيمة فوراً كما في برامج المحاسبة.
                        if (typeof target.select === 'function') {
                            target.select();
                        }
                    });
                },

                // فهارس الاعمدة القابلة للتحرير مرتبة، لتخطي الاعمدة المحسوبة اثناء التنقل.
                navigableCols() {
                    const indexes = [];

                    this.columns.forEach((column, index) => {
                        if (this.editable(column)) {
                            indexes.push(index);
                        }
                    });

                    return indexes;
                },

                moveNext(rowIndex, colIndex) {
                    const cols = this.navigableCols();
                    const position = cols.indexOf(colIndex);

                    if (position < cols.length - 1) {
                        return this.focusCell(rowIndex, cols[position + 1]);
                    }

                    // نهاية الصف: الانتقال للصف التالي، وانشاؤه عند اللزوم.
                    if (rowIndex < this.rows.length - 1) {
                        return this.focusCell(rowIndex + 1, cols[0]);
                    }

                    if (this.config.autoAppend) {
                        this.addRow();

                        return this.focusCell(rowIndex + 1, cols[0]);
                    }
                },

                movePrevious(rowIndex, colIndex) {
                    const cols = this.navigableCols();
                    const position = cols.indexOf(colIndex);

                    if (position > 0) {
                        return this.focusCell(rowIndex, cols[position - 1]);
                    }

                    if (rowIndex > 0) {
                        return this.focusCell(rowIndex - 1, cols[cols.length - 1]);
                    }
                },

                moveRow(rowIndex, colIndex, delta) {
                    const target = rowIndex + delta;

                    if (target >= 0 && target < this.rows.length) {
                        this.focusCell(target, colIndex);
                    }
                },

                /* ------------------------ الصفوف ------------------------- */

                addRow(afterIndex = null) {
                    if (afterIndex === null) {
                        this.rows.push(this.blankRow());
                    } else {
                        this.rows.splice(afterIndex + 1, 0, this.blankRow());
                    }

                    this.scheduleSync();
                },

                removeRow(rowIndex) {
                    if (! this.config.deletable) {
                        return;
                    }

                    this.rows.splice(rowIndex, 1);

                    while (this.rows.length < (this.config.minRows || 0)) {
                        this.rows.push(this.blankRow());
                    }

                    this.scheduleSync();
                    this.focusCell(Math.max(rowIndex - 1, 0), this.navigableCols()[0]);
                },

                /* ------------------- لوحة المفاتيح ------------------- */

                onKeydown(event, rowIndex, colIndex) {
                    const key = event.key;

                    if (key === 'Enter' || key === 'Tab') {
                        event.preventDefault();

                        return event.shiftKey
                            ? this.movePrevious(rowIndex, colIndex)
                            : this.moveNext(rowIndex, colIndex);
                    }

                    if (key === 'ArrowDown') {
                        event.preventDefault();

                        return this.moveRow(rowIndex, colIndex, 1);
                    }

                    if (key === 'ArrowUp') {
                        event.preventDefault();

                        return this.moveRow(rowIndex, colIndex, -1);
                    }

                    // الاسهم الافقية تبقى للمؤشر داخل النص، والانتقال بين الاعمدة عبر Ctrl.
                    if (event.ctrlKey && (key === 'ArrowRight' || key === 'ArrowLeft')) {
                        event.preventDefault();

                        const forward = document.dir === 'rtl' ? key === 'ArrowLeft' : key === 'ArrowRight';

                        return forward
                            ? this.moveNext(rowIndex, colIndex)
                            : this.movePrevious(rowIndex, colIndex);
                    }

                    if (event.ctrlKey && key === 'Enter') {
                        event.preventDefault();
                        this.addRow(rowIndex);

                        return this.focusCell(rowIndex + 1, this.navigableCols()[0]);
                    }

                    if (event.ctrlKey && (key === 'Delete' || key === 'Backspace')) {
                        event.preventDefault();

                        return this.removeRow(rowIndex);
                    }

                    if (key === 'Escape') {
                        event.preventDefault();
                        event.target.blur();
                    }
                },

                /* -------------------- المزامنة -------------------- */

                // الكتابة لا تُرسل للخادم مباشرة؛ المزامنة مؤجلة ومجمّعة حفاظاً على السرعة.
                scheduleSync() {
                    if (! this.config.statePath || ! this.$wire) {
                        return;
                    }

                    clearTimeout(this.syncTimer);
                    this.syncTimer = setTimeout(() => this.sync(), this.config.syncDelay ?? 400);
                },

                sync() {
                    if (! this.config.statePath || ! this.$wire) {
                        return;
                    }

                    const payload = this.rows.map((row) => {
                        const clean = { ...row };
                        delete clean.__uid;

                        // الاعمدة المحسوبة تُرسل بقيمها النهائية.
                        Object.keys(this.computers).forEach((key) => {
                            clean[key] = this.computers[key](row, 0);
                        });

                        return clean;
                    });

                    // false = تحديث الحالة دون اعادة رسم المكوّن.
                    this.$wire.set(this.config.statePath, payload, false);
                },
            }));
        });
    </script>
@endonce

<div
    x-data="keyboardTable({
        columns: {{ Js::from($columns) }},
        rows: {{ Js::from($rows ?? []) }},
        newRow: {{ Js::from($newRow) }},
        statePath: {{ Js::from($statePath) }},
        autoAppend: {{ Js::from((bool) $autoAppend) }},
        minRows: {{ Js::from((int) $minRows) }},
        deletable: {{ Js::from((bool) $deletable) }},
    })"
    x-on:keyboard-table-sync.window="sync()"
    {{ $attributes->merge(['class' => 'overflow-x-auto']) }}
>
    <table class="w-full text-start text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                @foreach ($columns as $column)
                    <th
                        class="py-2 pe-3 font-medium {{ ($column['align'] ?? null) === 'end' ? 'text-end' : 'text-start' }}"
                        @isset($column['width']) style="width: {{ $column['width'] }}" @endisset
                    >
                        {{ $column['label'] ?? '' }}
                    </th>
                @endforeach

                @if ($deletable)
                    <th class="w-10 py-2 ps-3"></th>
                @endif
            </tr>
        </thead>

        <tbody>
            <template x-for="(row, rowIndex) in rows" :key="row.__uid">
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    @foreach ($columns as $colIndex => $column)
                        <td class="py-1 pe-3 {{ ($column['align'] ?? null) === 'end' ? 'text-end' : '' }}">
                            @php $type = $column['type'] ?? 'text'; @endphp

                            @if ($type === 'select')
                                <select
                                    data-cell
                                    :data-row="rowIndex"
                                    data-col="{{ $colIndex }}"
                                    x-model="row['{{ $column['key'] }}']"
                                    x-on:keydown="onKeydown($event, rowIndex, {{ $colIndex }})"
                                    x-on:change="scheduleSync()"
                                    class="form-input w-full py-1 text-sm"
                                >
                                    @foreach ($column['options'] ?? [] as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($type === 'readonly' || isset($column['compute']))
                                <span
                                    class="block px-1 py-1 text-gray-500 dark:text-gray-400"
                                    x-text="display(row, {{ Js::from($column) }}, rowIndex)"
                                ></span>
                            @else
                                <input
                                    data-cell
                                    :data-row="rowIndex"
                                    data-col="{{ $colIndex }}"
                                    type="{{ $type }}"
                                    @isset($column['step']) step="{{ $column['step'] }}" @endisset
                                    @isset($column['min']) min="{{ $column['min'] }}" @endisset
                                    x-model="row['{{ $column['key'] }}']"
                                    x-on:keydown="onKeydown($event, rowIndex, {{ $colIndex }})"
                                    x-on:input="scheduleSync()"
                                    x-on:focus="$event.target.select()"
                                    class="form-input w-full py-1 text-sm {{ ($column['align'] ?? null) === 'end' ? 'text-end' : '' }}"
                                    autocomplete="off"
                                />
                            @endif
                        </td>
                    @endforeach

                    @if ($deletable)
                        <td class="py-1 ps-3 text-end">
                            <button
                                type="button"
                                x-on:click="removeRow(rowIndex)"
                                tabindex="-1"
                                class="text-gray-400 transition hover:text-red-600"
                                title="{{ __('Remove') }}"
                            >
                                @svg('heroicon-o-trash', 'h-4 w-4')
                            </button>
                        </td>
                    @endif
                </tr>
            </template>
        </tbody>

        @if ($showFooter)
            <tfoot>
                <tr class="text-gray-700 dark:text-gray-300">
                    {{ $footer ?? '' }}
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="mt-2 flex items-center justify-between text-xs text-gray-400">
        <button
            type="button"
            x-on:click="addRow(); focusCell(rows.length - 1, navigableCols()[0])"
            class="rounded-md border border-gray-300 px-2 py-1 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
        >
            + {{ __('Add Row') }}
        </button>

        <span>{{ __('Enter: next field · Ctrl+Enter: new row · Ctrl+Delete: remove row') }}</span>
    </div>
</div>
