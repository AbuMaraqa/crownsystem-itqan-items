<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Entities\User;
use Spatie\Activitylog\Models\Activity;

/**
 * ما يعرضه زر «معلومات» في كل جدول (RecordInfoModal.vue): من أضاف السجل ومتى، وآخر 50 نشاطاً عليه
 * بقيم مقروءة (أسماء الحقول مترجمة، والمعرّفات أسماء، والتعدادات تسميات) بدل JSON خام.
 * تُرسل حقولاً صريحة فلا يتسرّب أي عمود من المستخدم أو السجل.
 */
final class RecordInfo
{
    private const LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public static function of(Model $record): array
    {
        $activities = method_exists($record, 'activities')
            ? $record->activities()->with('causer')->latest()->latest('id')->limit(self::LIMIT)->get()
            : collect();

        $activities->pluck('causer')->filter(fn ($causer) => $causer instanceof User)->each->loadMissing('media');

        return [
            'created_by' => UserCellData::of(self::creator($record, $activities->last())),
            'created_at' => $record->getAttribute('created_at')?->format('Y-m-d H:i'),
            'updated_at' => $record->getAttribute('updated_at')?->format('Y-m-d H:i'),
            'activities' => $activities->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'event' => ActivityLogFormatter::event($activity),
                'description' => ActivityLogFormatter::eventLabel($activity),
                'causer' => $activity->causer instanceof User ? UserCellData::of($activity->causer) : null,
                'changes' => ActivityLogFormatter::rows($activity),
                'created_at' => $activity->created_at?->format('Y-m-d H:i'),
                'created_ago' => $activity->created_at?->diffForHumans(),
            ])->all(),
        ];
    }

    /**
     * صاحب السجل: علاقة createdBy إن وُجدت، وإلا منفّذ نشاط الإنشاء.
     */
    private static function creator(Model $record, ?Activity $oldest): ?User
    {
        if (method_exists($record, 'createdBy')) {
            $record->loadMissing('createdBy.media');
            $creator = $record->getRelation('createdBy');

            if ($creator instanceof User) {
                return $creator;
            }
        }

        if ($oldest !== null && ActivityLogFormatter::event($oldest) === 'created' && $oldest->causer instanceof User) {
            return $oldest->causer;
        }

        return null;
    }
}
