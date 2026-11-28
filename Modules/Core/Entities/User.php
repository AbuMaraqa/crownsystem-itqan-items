<?php

namespace Modules\Core\Entities;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use ArPHP\I18N\Arabic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Intervention\Image\Encoders\PngEncoder;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravolt\Avatar\Avatar;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasCreator;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Support\UserCellData;
use Modules\Items\Entities\Order;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property string|null $profile_photo_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $avatar_url
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \Spatie\MediaLibrary\MediaCollections\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read string $profile_photo_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCurrentTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereProfilePhotoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements HasMedia
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    /**
     * الموديل خارج App\Models فلا يستطيع لارافيل استنتاج الفاكتوري تلقائياً.
     */
    protected static function newFactory(): \Database\Factories\UserFactory
    {
        return \Database\Factories\UserFactory::new();
    }

    use HasCreator;
    use HasRoles;
    use HasSearchIndex;
    use InteractsWithMedia;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * البحث في المستخدمين والزبائن والموردين: بالاسم والهاتف والبريد.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['name', 'phone', 'email'];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'name',
        'name_en',
        'email',
        'phone',
        'password',
        'branch_id',
        'point_balance',
        'loyalty_tier_id',
        'address',
        'tax_number',
        'customer_group_id',
        'profit_margin',
        'use_last_price',
        'price_list_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        //        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profit_margin' => 'decimal:4',
            'use_last_price' => 'boolean',
        ];
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function routeNotificationForFcm()
    {
        return $this->deviceTokens()->pluck('token')->toArray();
    }

    /**
     * رابط صورة المستخدم: الصورة المرفوعة، وإلا الصورة الافتراضية بالأحرف الأولى.
     * هذا هو المصدر الوحيد لصورة المستخدم في النظام.
     */
    public function getAvatarUrlAttribute(): string
    {
        return $this->getFirstMediaUrl('avatar') ?: $this->defaultAvatarDataUrl();
    }

    /**
     * الصورة الافتراضية تُولَّد مرة واحدة لكل اسم وتُخزَّن مؤقتًا؛ توليدها في كل استدعاء
     * يبطئ الجداول التي تعرض عشرات المستخدمين.
     */
    protected function defaultAvatarDataUrl(): string
    {
        return Cache::remember(
            'user-default-avatar:'.md5($this->getAvatarName()),
            now()->addDays(30),
            fn () => 'data:image/png;base64,'.base64_encode($this->generateDefaultAvatar())
        );
    }

    protected function getAvatarName(): string
    {
        return $this->name
            ?: ($this->email ? explode('@', $this->email)[0] : 'User_'.$this->id);
    }

    public function registerMediaCollections(): void
    {
        $fallbackDataUrl = $this->defaultAvatarDataUrl();

        $this->addMediaCollection('avatar')
            ->singleFile()
            ->useFallbackUrl($fallbackDataUrl);

        $this->addMediaCollection('cover_photo')
            ->singleFile()
            ->useFallbackUrl($fallbackDataUrl);
    }

    protected function getDefaultRandomColor(): string
    {
        $colors = [
            '#f44336', '#E91E63', '#9C27B0', '#673AB7', '#3F51B5',
            '#2196F3', '#03A9F4', '#00BCD4', '#009688', '#4CAF50',
            '#8BC34A', '#CDDC39', '#FFC107', '#FF9800', '#FF5722',
        ];

        return $colors[array_rand($colors)];
    }

    private function generateInitials(string $name): array
    {
        $name = trim($name);
        $isArabic = (bool) preg_match('/[\p{Arabic}]/u', $name);
        $initials = mb_strtoupper(mb_substr($name, 0, 1));

        return ['initials' => $initials, 'is_arabic' => $isArabic];
    }

    protected function generateDefaultAvatar(): string
    {
        // 1. Get the full name and generate initials data
        $name = $this->getAvatarName();
        $initialsData = $this->generateInitials($name);
        $initials = $initialsData['initials'];
        $isArabic = $initialsData['is_arabic'];

        $processedText = $initials;

        // 2. Process text ONLY if it is Arabic
        if ($isArabic) {
            $arabic = new Arabic('Glyphs');
            // The initials are passed directly without reversal
            $processedText = $arabic->utf8Glyphs($initials);
        }

        // 3. Create the avatar
        $avatar = new Avatar(config('laravolt.avatar'));
        $image = $avatar->create($processedText)
            ->setDimension(200, 200)
            ->setFontSize(70)
            ->getImageObject();

        return (string) $image->encode(new PngEncoder);
    }

    public function generateAvatar(): void
    {
        $pngData = $this->generateDefaultAvatar(); // هنا سيكون string جاهز

        $this->addMediaFromString($pngData)
            ->usingFileName("user-{$this->id}-avatar.png")
            ->toMediaCollection('avatar', 'media');
    }

    /**
     * اسم قديم لـ avatar_url، يبقى لأن واجهة الـ API تستخدمه.
     */
    public function getProfileImageUrlAttribute(): string
    {
        return $this->getAvatarUrlAttribute();
    }

    /**
     * عرض المستخدم الموحَّد (الصورة + الاسم + البريد) في كل النظام: $user->user_display_html
     * التصميم في core::partials.user-display، وفي جداول Filament يُستخدم UserColumn.
     */
    public function getUserDisplayHtmlAttribute(): HtmlString
    {
        return new HtmlString(view('core::partials.user-display', ['user' => $this])->render());
    }

    /**
     * المستخدم كما تعرضه جداول Vue ونوافذها (الصورة + الاسم + البريد) عبر مكوّن UserCell: $user->user_cell
     * المصدر الوحيد هو UserCellData::of(). يحتاج تحميل media مسبقاً عند عرض قائمة.
     *
     * @return array{id: int, name: string, avatar: string, detail: ?string}
     */
    public function getUserCellAttribute(): array
    {
        return UserCellData::of($this);
    }

    public function userModules(): HasMany
    {
        return $this->hasMany(UserModule::class)->where('is_active', 1);
    }

    public function scopeSupplierRole($query)
    {
        return $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'Supplier'));
    }

    public function getAvailableModules()
    {
        return $this->userModules()->pluck('module_name');
    }

    public function getModulesByPackage($packageSource)
    {
        return $this->userModules()
            ->where('package_source', $packageSource)
            ->get();
    }

    public function getAvailablePackages()
    {
        return $this->userModules()
            ->whereNotNull('package_source')
            ->pluck('package_source')
            ->unique();
    }

    public function activateModule($moduleName, $packageSource = null)
    {
        return $this->userModules()->updateOrCreate([
            'module_name' => $moduleName,
        ], [
            'is_active' => true,
            'package_source' => $packageSource,
        ]);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function scopeCustomerRole($query)
    {
        return $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'Customer'));
    }

    /** زبون او مورد: الاطراف التي تُحرَّر لها السندات والشيكات. */
    public function scopePartyRole($query)
    {
        return $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', ['Customer', 'Supplier']));
    }

    public function getPointBalance()
    {
        return $this->point_balance;
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'App.Models.User.'.$this->id;
    }

    //    public function routeNotificationForFcm()
    //    {
    //        return $this->getDeviceTokens();
    //    }
}
