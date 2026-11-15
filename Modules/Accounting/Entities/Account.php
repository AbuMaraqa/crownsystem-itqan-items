<?php

namespace Modules\Accounting\Entities;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Account extends Model implements TranslatableContract
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;
    use Translatable;

    public array $translatedAttributes = [
        'name',
        'description',
    ];

    public bool $useTranslationFallback = true;

    protected $fillable = [
        'code',
        'parent_id',
        'type',
        'normal_balance',
        'level',
        'path',
        'sort_order',
        'is_postable',
        'is_active',
        'created_by',
        'user_id',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'level' => 'integer',
        'sort_order' => 'integer',
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix') . 'accounts');
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            if ($account->parent_id && (int) $account->parent_id === (int) $account->id) {
                throw new DomainException(__('An account cannot be its own parent.'));
            }

            if ($account->exists && $account->parent_id && $account->wouldCreateCycle((int) $account->parent_id)) {
                throw new DomainException(__('This parent account would create a cycle.'));
            }

            $parent = $account->parent_id ? static::query()->find($account->parent_id) : null;
            $account->level = $parent ? $parent->level + 1 : 1;
            $account->normal_balance = $account->normal_balance ?: static::normalBalanceForType($account->type);

            $account->assertStructureRules($parent);
        });

        static::created(function (Account $account) {
            $account->refreshHierarchy();
            $account->parent?->convertToGroup();

            $locale = app()->getLocale();
            $translationData = request()->only($account->translatedAttributes);

            if (! empty($translationData)) {
                $translation = $account->translateOrNew($locale);
                $translation->fill($translationData);
                $translation->created_by = $translation->created_by ?? $account->created_by;
                $account->saveQuietly();
            }
        });

        static::updated(function (Account $account) {
            if ($account->wasChanged('parent_id')) {
                $account->parent?->convertToGroup();
            }

            if ($account->wasChanged('parent_id') || $account->wasChanged('level') || $account->wasChanged('path')) {
                $account->refreshHierarchy();
                $account->refreshDescendantPaths();
            }
        });

        static::deleting(function (Account $account) {
            if ($account->children()->exists()) {
                throw new DomainException(__('Cannot delete an account that has child accounts.'));
            }

            if ($account->journalLines()->withTrashed()->exists() || $account->balances()->withTrashed()->exists()) {
                throw new DomainException(__('Cannot delete an account that has journal lines.'));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public static function normalBalanceForType(AccountType|int|string|null $type): NormalBalance
    {
        $type = $type instanceof AccountType ? $type : AccountType::tryFrom((int) $type);

        return match ($type) {
            AccountType::ASSET, AccountType::EXPENSE => NormalBalance::DEBIT,
            AccountType::LIABILITY, AccountType::EQUITY, AccountType::REVENUE => NormalBalance::CREDIT,
            default => NormalBalance::DEBIT,
        };
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive.translations');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class, 'account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true);
    }

    /**
     * قواعد الشجرة (كما في ERPNext: حساب مجموعة / حساب طرفي):
     * - الحساب الفرعي من نفس نوع ابيه، فلا ينتقل رصيده بين الميزانية وقائمة الدخل.
     * - لا يُضاف فرع تحت حساب طرفي عليه قيود (رصيده سيضيع بين الاب وابنائه).
     * - الحساب الذي له ابناء لا يقبل الترحيل.
     * - لا يتغير نوع حساب عليه قيود، ولا يُلغى قبوله للترحيل.
     */
    private function assertStructureRules(?Account $parent): void
    {
        if ($parent) {
            if ($parent->type !== $this->type) {
                throw new DomainException(__('A child account must have the same type as its parent.'));
            }

            if ($parent->is_postable && $parent->hasJournalLines()) {
                throw new DomainException(__('Cannot add a child account under an account that has journal entries.'));
            }
        }

        if (! $this->exists) {
            return;
        }

        if ($this->is_postable && $this->children()->exists()) {
            throw new DomainException(__('An account that has child accounts cannot accept postings.'));
        }

        if (($this->isDirty('type') || ($this->isDirty('is_postable') && ! $this->is_postable)) && $this->hasJournalLines()) {
            throw new DomainException(__('Cannot change the type or posting option of an account that has journal entries.'));
        }
    }

    public function hasJournalLines(): bool
    {
        return $this->exists && $this->journalLines()->withTrashed()->exists();
    }

    /**
     * يحوّل الحساب الى حساب مجموعة عند اضافة اول فرع تحته.
     */
    public function convertToGroup(): void
    {
        if ($this->is_postable) {
            $this->forceFill(['is_postable' => false])->saveQuietly();
        }
    }

    public function wouldCreateCycle(int $candidateParentId): bool
    {
        if (! $this->exists) {
            return false;
        }

        $candidate = static::query()->find($candidateParentId);

        if (! $candidate) {
            return false;
        }

        return (int) $candidate->id === (int) $this->id
            || str_contains((string) $candidate->path, '/' . $this->id . '/');
    }

    public function refreshHierarchy(): void
    {
        $parent = $this->parent_id ? static::query()->find($this->parent_id) : null;
        $level = $parent ? $parent->level + 1 : 1;
        $path = ($parent?->path ?: '/') . $this->id . '/';

        if ($this->level !== $level || $this->path !== $path) {
            $this->forceFill([
                'level' => $level,
                'path' => $path,
            ])->saveQuietly();
        }
    }

    public function refreshDescendantPaths(): void
    {
        $this->children()->get()->each(function (Account $child) {
            $child->refreshHierarchy();
            $child->refreshDescendantPaths();
        });
    }

    public static function getTreeData(): array
    {
        $accounts = static::query()
            ->with('translations')
            ->orderBy('path')
            ->orderBy('code')
            ->get();

        $childrenByParent = $accounts->groupBy(fn(Account $account) => (int) ($account->parent_id ?: 0));

        return static::mapTreeLevel($childrenByParent, 0);
    }

    public static function mapToTree(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'label' => trim($account->code . ' - ' . $account->name),
            'type' => $account->type?->value(),
            'is_postable' => $account->is_postable,
            'children' => $account->childrenRecursive
                ->map(fn(Account $child) => static::mapToTree($child))
                ->values()
                ->toArray(),
        ];
    }

    private static function mapTreeLevel(Collection $childrenByParent, int $parentId): array
    {
        return $childrenByParent->get($parentId, collect())
            ->sortBy('code', SORT_NATURAL)
            ->map(fn(Account $account) => static::mapFlatAccountToTree($account, $childrenByParent))
            ->values()
            ->toArray();
    }

    private static function mapFlatAccountToTree(Account $account, Collection $childrenByParent): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'label' => trim($account->code . ' - ' . $account->name),
            'type' => $account->type?->value(),
            'is_postable' => $account->is_postable,
            'children' => static::mapTreeLevel($childrenByParent, (int) $account->id),
        ];
    }

    public static function options(bool $postableOnly = false): array
    {
        $query = static::query()->with('translations')->orderBy('code');

        if ($postableOnly) {
            $query->postable();
        }

        return $query->get()
            ->mapWithKeys(fn(Account $account) => [
                $account->id => str_repeat('-- ', max($account->level - 1, 0)) . $account->code . ' - ' . $account->name,
            ])
            ->toArray();
    }
}
