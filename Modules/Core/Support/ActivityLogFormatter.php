<?php

namespace Modules\Core\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Entities\User;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class ActivityLogFormatter
{
    private const EMPTY_VALUE = '-';

    private const SENSITIVE_FIELDS = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    private const USER_FIELDS = [
        'created_by',
        'updated_by',
        'deleted_by',
        'posted_by',
    ];

    private static array $userNames = [];

    private static array $relatedLabels = [];

    public static function eventLabel(Activity $activity): string
    {
        return match (self::eventName($activity)) {
            'created' => __('Created Record'),
            'updated' => __('Updated Record'),
            'deleted' => __('Deleted Record'),
            'restored' => __('Restored Record'),
            default => __(Str::headline((string) ($activity->event ?: $activity->description))),
        };
    }

    /**
     * اسم الحدث الموحَّد (created / updated / deleted / restored أو فارغ) لتلوين سجل النشاط في الواجهة.
     */
    public static function event(Activity $activity): string
    {
        return self::eventName($activity);
    }

    public static function eventColor(Activity $activity): string
    {
        return match (self::eventName($activity)) {
            'created' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
            'updated' => 'bg-blue-100 text-blue-700 ring-blue-200',
            'deleted' => 'bg-red-100 text-red-700 ring-red-200',
            'restored' => 'bg-amber-100 text-amber-700 ring-amber-200',
            default => 'bg-gray-100 text-gray-700 ring-gray-200',
        };
    }

    public static function subjectLabel(Activity $activity): string
    {
        if (! $activity->subject_type) {
            return __('Unknown');
        }

        return __(Str::headline(class_basename($activity->subject_type)));
    }

    public static function rows(Activity $activity): array
    {
        $properties = self::propertiesArray($activity);
        $old = self::arrayValue($properties['old'] ?? []);
        $attributes = self::arrayValue($properties['attributes'] ?? []);
        $keys = collect(array_keys($old))
            ->merge(array_keys($attributes))
            ->unique()
            ->reject(fn (string $field) => in_array($field, self::SENSITIVE_FIELDS, true))
            ->filter(fn (string $field) => self::hasUsefulValue($old, $attributes, $field))
            ->values();

        return $keys
            ->map(fn (string $field) => [
                'field' => $field,
                'label' => self::fieldLabel($field),
                'old' => array_key_exists($field, $old)
                    ? self::formatValue($old[$field], $activity, $field)
                    : self::EMPTY_VALUE,
                'new' => array_key_exists($field, $attributes)
                    ? self::formatValue($attributes[$field], $activity, $field)
                    : self::EMPTY_VALUE,
                'changed' => self::changed($old, $attributes, $field),
            ])
            ->all();
    }

    public static function rawProperties(Activity $activity): string
    {
        return json_encode(
            self::propertiesArray($activity),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }

    private static function propertiesArray(Activity $activity): array
    {
        $properties = $activity->properties;

        if ($properties instanceof Arrayable) {
            return $properties->toArray();
        }

        return is_array($properties) ? $properties : [];
    }

    private static function arrayValue(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return is_array($value) ? $value : [];
    }

    private static function fieldLabel(string $field): string
    {
        $label = Str::of($field)
            ->replaceEnd('_id', '')
            ->replace('_', ' ')
            ->headline()
            ->toString();

        return __($label);
    }

    private static function eventName(Activity $activity): string
    {
        $event = strtolower((string) $activity->event);

        if ($event !== '') {
            return $event;
        }

        $description = strtolower((string) $activity->description);

        foreach (['created', 'updated', 'deleted', 'restored'] as $knownEvent) {
            if (str_contains($description, "has been {$knownEvent}") || str_contains($description, $knownEvent)) {
                return $knownEvent;
            }
        }

        return '';
    }

    private static function changed(array $old, array $attributes, string $field): bool
    {
        if (! array_key_exists($field, $old) || ! array_key_exists($field, $attributes)) {
            return true;
        }

        return $old[$field] != $attributes[$field];
    }

    private static function hasUsefulValue(array $old, array $attributes, string $field): bool
    {
        $hasOld = array_key_exists($field, $old);
        $hasNew = array_key_exists($field, $attributes);

        if (! $hasOld && $hasNew) {
            return ! self::blankValue($attributes[$field]);
        }

        if ($hasOld && ! $hasNew) {
            return ! self::blankValue($old[$field]);
        }

        return true;
    }

    private static function blankValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function formatValue(mixed $value, Activity $activity, string $field): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY_VALUE;
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (self::isBooleanField($field) && is_numeric($value)) {
            return (int) $value === 1 ? __('Yes') : __('No');
        }

        if (in_array($field, self::USER_FIELDS, true)) {
            return self::userName($value);
        }

        $relatedValue = self::relatedModelLabel($activity->subject_type, $field, $value);

        if ($relatedValue !== null) {
            return $relatedValue;
        }

        $enumValue = self::enumValue($activity->subject_type, $field, $value);

        if ($enumValue !== null) {
            return $enumValue;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        if (is_string($value) && self::looksLikeDate($value)) {
            try {
                return Carbon::parse($value)
                    ->timezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return $value;
            }
        }

        return (string) $value;
    }

    private static function isBooleanField(string $field): bool
    {
        return str_starts_with($field, 'is_')
            || in_array($field, ['active', 'default', 'published', 'featured'], true);
    }

    private static function userName(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        $id = (int) $value;

        if (! array_key_exists($id, self::$userNames)) {
            self::$userNames[$id] = User::query()->whereKey($id)->value('name') ?: "#{$id}";
        }

        return self::$userNames[$id];
    }

    private static function relatedModelLabel(?string $subjectType, string $field, mixed $value): ?string
    {
        if (! $subjectType || ! class_exists($subjectType) || ! str_ends_with($field, '_id') || ! is_numeric($value)) {
            return null;
        }

        try {
            $model = new $subjectType;

            if (! $model instanceof Model) {
                return null;
            }

            $relationName = Str::camel(Str::beforeLast($field, '_id'));

            if (! method_exists($model, $relationName)) {
                return null;
            }

            $relation = $model->{$relationName}();

            if (! $relation instanceof BelongsTo) {
                return null;
            }

            $relatedClass = $relation->getRelated()::class;
            $id = (int) $value;

            if (! isset(self::$relatedLabels[$relatedClass][$id])) {
                $related = $relation->getRelated()->newQuery()->whereKey($id)->first();
                self::$relatedLabels[$relatedClass][$id] = $related
                    ? self::modelLabel($related)
                    : "#{$id}";
            }

            return self::$relatedLabels[$relatedClass][$id];
        } catch (Throwable) {
            return null;
        }
    }

    private static function modelLabel(Model $model): string
    {
        foreach (['entry_number', 'code', 'name', 'title', 'email'] as $attribute) {
            $value = data_get($model, $attribute);

            if ($value) {
                if ($attribute === 'code' && data_get($model, 'name')) {
                    return $value.' - '.data_get($model, 'name');
                }

                return (string) $value;
            }
        }

        return __(Str::headline(class_basename($model))).' #'.$model->getKey();
    }

    private static function enumValue(?string $subjectType, string $field, mixed $value): ?string
    {
        if (! $subjectType || ! class_exists($subjectType)) {
            return null;
        }

        try {
            $model = new $subjectType;

            if (! $model instanceof Model) {
                return null;
            }

            $cast = $model->getCasts()[$field] ?? null;

            if (! is_string($cast) || ! enum_exists($cast) || ! is_subclass_of($cast, BackedEnum::class)) {
                return null;
            }

            $enum = self::tryEnumFrom($cast, $value);

            if (! $enum) {
                return null;
            }

            return method_exists($enum, 'getLabel') ? $enum->getLabel() : __(Str::headline($enum->name));
        } catch (Throwable) {
            return null;
        }
    }

    private static function tryEnumFrom(string $enumClass, mixed $value): ?BackedEnum
    {
        foreach ([$value, is_numeric($value) ? (int) $value : $value, (string) $value] as $candidate) {
            try {
                $enum = $enumClass::tryFrom($candidate);

                if ($enum instanceof BackedEnum) {
                    return $enum;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private static function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}(T|\s)/', $value);
    }
}
