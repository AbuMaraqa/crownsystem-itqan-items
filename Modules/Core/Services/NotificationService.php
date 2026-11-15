<?php

namespace Modules\Core\Services;

use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Core\Entities\User;
use Modules\Core\Enums\NotificationTone;
use Modules\Core\Notifications\SystemNotification;
use Throwable;

/**
 * خدمة الاشعارات: نقطة واحدة لارسال اشعار لأي مستخدم في النظام (عرض سعر، طلبية، فاتورة، سند ...)
 * ولإدارة حالته (مقروء / غير مقروء) وحذفه.
 *
 * الارسال:
 *   app(NotificationService::class)->send(
 *       recipients: $user,                       // مستخدم، رقم مستخدم، او مجموعة منهما
 *       title: 'New :document',                  // مفتاح ترجمة (او نص عادي)
 *       message: ':document #:reference has been sent to you by :sender.',
 *       params: ['document' => NotificationService::trans('Sales Quotation'), 'reference' => 'SQ-1'],
 *       url: route('accounting.sales-quotations.edit', $quotation),
 *       subject: $quotation,
 *   );
 *
 * العنوان والنص يحفظان كمفاتيح ترجمة مع المعاملات، فيُعرضان بلغة المستخدم وقت القراءة (present)،
 * ويحفظ معهما نص جاهز بلغة الارسال للجوال والـ API.
 */
class NotificationService
{
    /**
     * اسماء الايقونات المختصرة => ايقونة solar. يمكن ايضاً تمرير اسم solar كامل في icon.
     */
    public const ICONS = [
        'bell' => 'solar-bell-bold-duotone',
        'document' => 'solar-document-text-bold-duotone',
    ];

    protected static bool $muted = false;

    /**
     * يرسل الاشعار ويعيد عدد المستلمين الذين وصلهم فعلاً.
     * فشل البث او FCM لا يوقف العملية التي استدعت الارسال؛ يسجل في السجل فقط.
     *
     * @param  User|int|iterable<User|int>|null  $recipients
     * @param  array<string, mixed>  $params  معاملات الترجمة؛ استخدم trans() او enum() للقيم التي تترجم وقت العرض
     * @param  bool  $skipSender  لا يرسل للمستخدم الذي قام بالعملية نفسه
     */
    public function send(
        mixed $recipients,
        string $title,
        string $message = '',
        array $params = [],
        ?string $url = null,
        ?Model $subject = null,
        ?User $sender = null,
        string $event = 'general',
        string $icon = 'bell',
        string $color = 'primary',
        array $extra = [],
        bool $skipSender = true,
    ): int {
        if (static::$muted) {
            return 0;
        }

        $sender ??= auth()->user() instanceof User ? auth()->user() : null;
        $users = $this->resolveRecipients($recipients);

        if ($skipSender && $sender) {
            $users = $users->reject(fn (User $user) => $user->is($sender));
        }

        if ($users->isEmpty()) {
            return 0;
        }

        $params += ['sender' => $sender?->name ?? __('System')];

        $payload = [
            'title' => $this->translate($title, $params),
            'message' => $this->translate($message, $params),
            'title_key' => $title,
            'message_key' => $message,
            'params' => $params,
            'url' => $url,
            'icon' => $icon,
            'color' => $color,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'sender_id' => $sender?->id,
            'sender_name' => $sender?->name,
            'extra' => $extra,
        ];

        $sent = 0;

        foreach ($users as $user) {
            try {
                $user->notify(new SystemNotification($payload));
                $sent++;
            } catch (Throwable $e) {
                // سجل قاعدة البيانات يحفظ قبل البث، فالاشعار يبقى ظاهراً في النظام حتى لو تعطل Reverb او FCM.
                Log::warning('Notification delivery failed', [
                    'user_id' => $user->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * يوقف الارسال اثناء تنفيذ الدالة (استيراد بيانات، seeders، عمليات جماعية).
     */
    public static function withoutNotifications(callable $callback): mixed
    {
        $previous = static::$muted;
        static::$muted = true;

        try {
            return $callback();
        } finally {
            static::$muted = $previous;
        }
    }

    /**
     * معامل يترجم بلغة القارئ وقت العرض (مثل اسم نوع المستند).
     */
    public static function trans(string $key): array
    {
        return ['__trans' => $key];
    }

    /**
     * معامل حالة (enum) يعرض بتسميتها بلغة القارئ وقت العرض.
     */
    public static function enum(BackedEnum $case): array
    {
        return ['__enum' => $case::class, 'value' => $case->value];
    }

    // ---------------------------------------------------------------- القراءة

    /**
     * اشعارات المستخدم، مع تصفية اختيارية: true المقروءة، false غير المقروءة، null الكل.
     */
    public function query(User $user, ?bool $read = null): Builder
    {
        return $user->notifications()
            ->getQuery()
            ->when($read === true, fn (Builder $q) => $q->whereNotNull('read_at'))
            ->when($read === false, fn (Builder $q) => $q->whereNull('read_at'));
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * يجهز الاشعار للعرض بلغة المستخدم الحالية.
     */
    public function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $params = $data['params'] ?? [];
        $icon = (string) ($data['icon'] ?? 'bell');

        return [
            'id' => $notification->id,
            'title' => isset($data['title_key']) ? $this->translate($data['title_key'], $params) : ($data['title'] ?? ''),
            'message' => isset($data['message_key']) ? $this->translate($data['message_key'], $params) : ($data['message'] ?? ''),
            'url' => $data['url'] ?? null,
            'icon' => $icon,
            'icon_svg' => self::ICONS[$icon] ?? (str_starts_with($icon, 'solar-') ? $icon : self::ICONS['bell']),
            'color' => $data['color'] ?? 'primary',
            'tone' => NotificationTone::fromValue($data['color'] ?? null),
            'event' => $data['event'] ?? 'general',
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'sender_id' => $data['sender_id'] ?? null,
            'sender_name' => $data['sender_name'] ?? null,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at,
            'resolved_at' => $data['resolved_at'] ?? null,
            'created_at' => $notification->created_at,
        ];
    }

    // ---------------------------------------------------------------- الحالة

    /**
     * كل العمليات التالية مقيدة باشعارات المستخدم نفسه، فلا يستطيع تعديل اشعارات غيره.
     *
     * @param  string|array<string>  $ids
     */
    public function markAsRead(User $user, string|array $ids): int
    {
        return $this->query($user)->whereIn('id', Arr::wrap($ids))->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @param  string|array<string>  $ids
     */
    public function markAsUnread(User $user, string|array $ids): int
    {
        return $this->query($user)->whereIn('id', Arr::wrap($ids))->whereNotNull('read_at')->update(['read_at' => null]);
    }

    /**
     * يقلب حالة الاشعار ويعيد الحالة الجديدة (true = مقروء).
     */
    public function toggleRead(User $user, string $id): bool
    {
        $notification = $this->query($user)->findOrFail($id);
        $notification->forceFill(['read_at' => $notification->read_at ? null : now()])->save();

        return $notification->read_at !== null;
    }

    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * يعلّم اشعارات حدث معيّن عن سجل معيّن «تمت المعالجة» عند كل المستلمين، دون حذفها
     * (مثل تنبيهات صنف بعد توريده). تصبح مقروءة، ويبقى نصها كما هو في السجل.
     */
    public function resolve(string $event, Model $subject): int
    {
        $notifications = DatabaseNotification::query()
            ->where('data->event', $event)
            ->where('data->subject_type', $subject->getMorphClass())
            ->where('data->subject_id', $subject->getKey())
            ->whereNull('data->resolved_at')
            ->get();

        foreach ($notifications as $notification) {
            $notification->forceFill([
                'data' => [...$notification->data, 'resolved_at' => now()->toIso8601String()],
                'read_at' => $notification->read_at ?? now(),
            ])->save();
        }

        return $notifications->count();
    }

    /**
     * @param  string|array<string>  $ids
     */
    public function delete(User $user, string|array $ids): int
    {
        return $this->query($user)->whereIn('id', Arr::wrap($ids))->delete();
    }

    /**
     * رابط الاشعار ان كان داخلياً او http(s) فقط؛ غير ذلك (javascript: ...) لا يُتبع ولا يُعرض.
     */
    public function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || blank($url) || $url === '#') {
            return null;
        }

        return (str_starts_with($url, '/') && ! str_starts_with($url, '//')) || preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    // ---------------------------------------------------------------- داخلي

    /**
     * @return Collection<int, User>
     */
    protected function resolveRecipients(mixed $recipients): Collection
    {
        $items = collect($recipients instanceof User || ! is_iterable($recipients) ? [$recipients] : $recipients)
            ->filter();

        $users = $items->filter(fn ($item) => $item instanceof User);
        $ids = $items->reject(fn ($item) => $item instanceof User)->map(fn ($id) => (int) $id)->filter();

        if ($ids->isNotEmpty()) {
            $users = $users->concat(User::query()->whereKey($ids->all())->get());
        }

        return $users->unique('id')->values();
    }

    protected function translate(string $key, array $params): string
    {
        if ($key === '') {
            return '';
        }

        $resolved = collect($params)->map(function ($value) {
            if (is_array($value) && isset($value['__trans'])) {
                return __($value['__trans']);
            }

            if (is_array($value) && isset($value['__enum'])) {
                $case = enum_exists($value['__enum']) ? $value['__enum']::tryFrom($value['value']) : null;

                return $case instanceof HasLabel ? $case->getLabel() : (string) $value['value'];
            }

            return is_scalar($value) || $value === null ? (string) $value : '';
        })->all();

        return __($key, $resolved);
    }
}
