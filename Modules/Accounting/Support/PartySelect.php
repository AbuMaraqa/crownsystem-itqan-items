<?php

namespace Modules\Accounting\Support;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Entities\User;

/**
 * حقل اختيار الطرف في المستندات: مستندات الشراء تعرض الموردين فقط، ومستندات البيع
 * تعرض الزبائن فقط، والسندات والشيكات تعرض الطرفين. المستخدم المحفوظ سابقا يظهر
 * اسمه دائما حتى لو تغير دوره.
 */
class PartySelect
{
    public static function supplier(string $name = 'client_id'): Select
    {
        return self::make($name, __('Supplier'), fn (Builder $query) => $query->supplierRole());
    }

    public static function customer(string $name = 'client_id'): Select
    {
        return self::make($name, __('Customer'), fn (Builder $query) => $query->customerRole());
    }

    public static function any(string $name = 'client_id'): Select
    {
        return self::make($name, __('Customer / Supplier'), fn (Builder $query) => $query->partyRole());
    }

    protected static function make(string $name, string $label, callable $scope): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => self::search($scope))
            ->getSearchResultsUsing(fn (string $search): array => self::search($scope, $search))
            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name'));
    }

    /**
     * نفس بحث الحقل للوحة Vue (RemoteSelect): $role هو supplier او customer او any.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function lookup(string $role, ?string $term = null): array
    {
        $scope = match ($role) {
            'supplier' => fn (Builder $query) => $query->supplierRole(),
            'customer' => fn (Builder $query) => $query->customerRole(),
            default => fn (Builder $query) => $query->partyRole(),
        };

        return collect(self::search($scope, $term))
            ->map(fn (string $name, int $id) => ['value' => $id, 'label' => $name])
            ->values()
            ->all();
    }

    /**
     * اول 50 طرفاً بالاسم، او من يطابق اسمه او هاتفه او بريده النص المكتوب.
     *
     * @return array<int, string>
     */
    protected static function search(callable $scope, ?string $term = null): array
    {
        return $scope(User::query())
            ->when(filled($term), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->toArray();
    }
}
