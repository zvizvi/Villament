<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Teams;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Resources\Teams\Pages\ListTeams;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\TeamUserResource;

/**
 * The parent of {@see TeamUserResource}.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $slug = 'teams';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
        ];
    }
}
