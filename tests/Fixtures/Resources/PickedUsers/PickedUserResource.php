<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\PickedUsers\Pages\ListPickedUsers;

/**
 * A list narrowed by a property the PAGE owns (`$pickedStatus`, set by a
 * toolbar picker in a real app), which also decides whether a filter exists -
 * the shape of a host app's donations list and its campaign picker.
 */
class PickedUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'picked-users';

    public static function table(Table $table): Table
    {
        $picked = $table->getLivewire()->pickedStatus ?? null;

        return $table
            ->columns([TextColumn::make('name')->sortable()])
            ->defaultSort('name')
            ->filters($picked === null ? [] : [
                SelectFilter::make('team_id')->options(['1' => 'One']),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPickedUsers::route('/'),
        ];
    }
}
