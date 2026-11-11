<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Teams\TeamResource;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\Pages\ListTeamUsers;

/**
 * A NESTED resource (a team's users) whose slug is `users` — the same slug as
 * the top-level UserResource. Registered BEFORE it on the panel, so a lookup
 * by slug alone finds this one first: the shape that sent a host app's
 * top-level donations list to its campaign-nested twin.
 */
class TeamUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $parentResource = TeamResource::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')->sortable()])
            ->defaultSort('name');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTeamUsers::route('/'),
        ];
    }
}
