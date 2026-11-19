<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages;

use Filament\Tables\Table;
use Zvizvi\Villament\Pages\VillamentManageRelatedRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\NotesTable;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\TeamRelationsResource;

class ManageTeamNotes extends VillamentManageRelatedRecords
{
    protected static string $resource = TeamRelationsResource::class;

    protected static string $relationship = 'notes';

    public function table(Table $table): Table
    {
        return NotesTable::configure($table);
    }
}
