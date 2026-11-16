<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Zvizvi\Villament\Concerns\HasVillamentRelationTable;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\NotesTable;

/**
 * Inside a relation group, and opted in through the trait rather than the
 * base class.
 */
class NotesRelationManager extends RelationManager
{
    use HasVillamentRelationTable;

    protected static string $relationship = 'notes';

    public function table(Table $table): Table
    {
        return NotesTable::configure($table);
    }
}
