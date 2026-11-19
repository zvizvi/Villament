<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\RelationManagers\VillamentRelationManager;

/**
 * A relation manager on a NESTED resource's record page: its owner record is
 * resolved under the parent team.
 */
class UserTeamsRelationManager extends VillamentRelationManager
{
    protected static string $relationship = 'teams';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([TextColumn::make('name')->sortable()])
            ->defaultSort('name');
    }
}
