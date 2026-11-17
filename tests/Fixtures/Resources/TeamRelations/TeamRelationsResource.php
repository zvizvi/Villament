<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;

/**
 * Relationship tables: a relation manager with an island next to its stock
 * twin (same table, so parity can compare the two), one inside a relation
 * group, and a ManageRelatedRecords page pair.
 */
class TeamRelationsResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $slug = 'team-relations';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
            RelationManagers\StockMembersRelationManager::class,
            RelationGroup::make('Notes', [
                RelationManagers\NotesRelationManager::class,
            ]),
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamRelations::route('/'),
            'edit' => Pages\EditTeamRelation::route('/{record}/edit'),
            'view' => Pages\ViewTeamRelation::route('/{record}'),
            'notes' => Pages\ManageTeamNotes::route('/{record}/notes'),
            'stock-notes' => Pages\StockManageTeamNotes::route('/{record}/stock-notes'),
        ];
    }
}
