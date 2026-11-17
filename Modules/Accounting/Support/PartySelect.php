<?php

namespace Modules\Accounting\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Entities\User;

/**
 * بحث حقل الطرف في المستندات (RemoteSelect في لوحة Vue): مستندات الشراء تعرض الموردين
 * فقط، ومستندات البيع تعرض الزبائن فقط، والسندات والشيكات تعرض الطرفين.
 */
class PartySelect
{
    /**
     * $role هو supplier او customer او any.
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
