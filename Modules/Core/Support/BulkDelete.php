<?php

namespace Modules\Core\Support;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الحذف الجماعي لكل الجداول (زر «حذف» في شريط التحديد في DataTable).
 *
 * - التحديد يُفحص بصرامة: قائمة معرّفات صحيحة غير مكررة، بحد أقصى صفحة واحدة، وكلها من صفوف الجدول نفسه
 *   (استعلامه)، وإلا رُفض الطلب كله برسالة.
 * - كل صف يُحذف بمسار حذفه المفرد (قواعده نفسها) في معاملة خاصة به: ما يُمنع حذفه (false، أو DomainException،
 *   أو ValidationException، أو قيد مرجعي في قاعدة البيانات) يُترك ويُذكر سببه،
 *   وما سواه يُحذف. يُعاد المرور ما دام شيء يُحذف، فأبٌ اختير مع أبنائه يُحذف بعدهم.
 * - النتيجة رسالة واحدة: نجاح، أو تحذير «حُذف كذا ولم يُحذف كذا لأن...»، أو خطأ إن لم يُحذف شيء.
 */
final class BulkDelete
{
    /** The largest page of a table (`AdminTable::PAGE_SIZES`): a selection never spans pages. */
    public const MAX = 100;

    /**
     * @param  Builder<Model>  $scope  the rows the user may delete: the table's own query
     * @param  Closure(Model): mixed  $delete  deletes one row; `false`, a DomainException or a ValidationException keeps it
     */
    public static function handle(Request $request, Builder $scope, Closure $delete): RedirectResponse
    {
        $ids = self::ids($request);
        $records = (clone $scope)->whereKey($ids)->get();

        if ($records->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => __('Some rows no longer exist. Refresh the page and try again.')]);
        }

        [$deleted, $reasons] = self::run($records->all(), $delete);

        if ($reasons === []) {
            return back()->with('success', __('The selected rows were deleted (:count).', ['count' => $deleted]));
        }

        $why = implode(' ', array_unique($reasons));

        if ($deleted === 0) {
            return back()->with('error', __('Nothing was deleted. :reason', ['reason' => $why]));
        }

        return back()->with('warning', __(':deleted deleted, :skipped not deleted. :reason', [
            'deleted' => $deleted,
            'skipped' => count($reasons),
            'reason' => $why,
        ]));
    }

    /**
     * @return list<int>
     */
    public static function ids(Request $request): array
    {
        $invalid = __('The selection is not valid. Refresh the page and try again.');

        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'ids.required' => __('Select at least one row.'),
            'ids.max' => __('Select :max rows at most.', ['max' => self::MAX]),
            'ids.array' => $invalid,
            'ids.*.required' => $invalid,
            'ids.*.integer' => $invalid,
            'ids.*.min' => $invalid,
            'ids.*.distinct' => $invalid,
        ])['ids'];

        return array_map('intval', $ids);
    }

    /**
     * @param  list<Model>  $pending
     * @param  Closure(Model): mixed  $delete
     * @return array{0: int, 1: array<int|string, string>} deleted count, and the reason per row kept
     */
    private static function run(array $pending, Closure $delete): array
    {
        $deleted = 0;

        do {
            $reasons = [];
            $kept = [];

            foreach ($pending as $record) {
                $reason = self::deleteOne($record, $delete);

                if ($reason === null) {
                    $deleted++;
                } else {
                    $reasons[$record->getKey()] = $reason;
                    $kept[] = $record;
                }
            }

            $progress = count($kept) < count($pending);
            $pending = $kept;
        } while ($progress && $pending !== []);

        return [$deleted, $reasons];
    }

    /**
     * Null when the row was deleted, otherwise why it stays.
     *
     * @param  Closure(Model): mixed  $delete
     */
    private static function deleteOne(Model $record, Closure $delete): ?string
    {
        try {
            return DB::transaction(fn () => $delete($record)) === false ? SafeDelete::message() : null;
        } catch (DomainException $exception) {
            return $exception->getMessage();
        } catch (ValidationException $exception) {
            // A business rule refused with a field message (inertia-views §5): the row stays, the message says why.
            return (string) collect($exception->errors())->flatten()->first();
        } catch (QueryException $exception) {
            if (! SafeDelete::isLinked($exception)) {
                throw $exception;
            }

            return SafeDelete::message();
        }
    }
}
