<?php

namespace Modules\Core\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * عمود المستخدم الموحَّد في جداول Filament (الصورة + الاسم + البريد) عبر $user->user_display_html.
 *
 * UserColumn::make('client')     عميل/مورد المستند
 * UserColumn::make('createdBy')  منشئ السجل
 * UserColumn::make()             عندما يكون السجل نفسه مستخدمًا (جداول العملاء والموردين)
 *
 * البحث والترتيب يعملان على عمود الاسم، وتُحمَّل صور المستخدمين مسبقًا لتجنّب استعلام لكل صف.
 */
class UserColumn extends TextColumn
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ? $name.'.name' : 'name')
            ->formatStateUsing(function ($state, Model $record) use ($name) {
                $user = $name ? data_get($record, $name) : $record;

                return $user?->user_display_html ?? e($state);
            })
            ->html();
    }

    public function applyEagerLoading(Builder|Relation $query): Builder|Relation
    {
        if (! $this->hasRelationship($query->getModel())) {
            return $query;
        }

        return $query->with([$this->getRelationshipName().'.media']);
    }
}
