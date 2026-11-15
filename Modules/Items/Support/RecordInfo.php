<?php

namespace Modules\Items\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\ActivityLogFormatter;
use Spatie\Activitylog\Models\Activity;

/**
 * What the Info action of a catalog table shows (the Vue side of the Filament InfoAction): who
 * created the record, when, and its latest 50 activities. Sent as explicit fields, so a user or a
 * model never leaks a column. The shape is the one malbo's `Pages/parts/RecordInfoModal.vue` reads.
 */
final class RecordInfo
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Model $record): array
    {
        $record->loadMissing('createdBy');

        return [
            'created_by' => $record->getRelation('createdBy')?->getAttribute('name'),
            'created_at' => $record->getAttribute('created_at')?->format('Y-m-d H:i'),
            'updated_at' => $record->getAttribute('updated_at')?->format('Y-m-d H:i'),
            'activities' => $record->activities()
                ->with('causer')
                ->latest()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'description' => ActivityLogFormatter::eventLabel($activity),
                    'causer' => $activity->causer?->getAttribute('name'),
                    'old' => self::changes($activity, 'old'),
                    'new' => self::changes($activity, 'attributes'),
                    'created_at' => $activity->created_at?->format('Y-m-d H:i'),
                    'created_ago' => $activity->created_at?->diffForHumans(),
                ])
                ->all(),
        ];
    }

    /**
     * activitylog v4 keeps the changed values in `properties` (`old` and `attributes`).
     *
     * @return array<string, mixed>|null
     */
    private static function changes(Activity $activity, string $key): ?array
    {
        $value = $activity->properties?->get($key);

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        return is_array($value) && $value !== [] ? $value : null;
    }
}
