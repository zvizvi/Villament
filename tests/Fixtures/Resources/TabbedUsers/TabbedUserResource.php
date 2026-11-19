<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\TabbedUsers\Pages\TabbedListUsers;

/**
 * A table that differs between its tabs, which Filament supports: the
 * `pending` tab shows a column and a filter the other tab does not.
 */
class TabbedUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'tabbed-users';

    public static function table(Table $table): Table
    {
        $offPending = fn ($livewire): bool => $livewire->activeTab !== 'pending';

        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('email')->hidden($offPending),
            ])
            ->filters([
                Filter::make('has_email')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email'))
                    ->hidden($offPending),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => TabbedListUsers::route('/'),
        ];
    }
}
