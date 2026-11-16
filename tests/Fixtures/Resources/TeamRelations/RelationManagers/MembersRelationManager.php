<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Zvizvi\Villament\RelationManagers\VillamentRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\MembersTable;

/**
 * The island one. Not lazy, so the host page's own HTML carries the island
 * (the notes manager covers the lazy path).
 */
class MembersRelationManager extends VillamentRelationManager
{
    protected static string $relationship = 'users';

    protected static ?bool $isLazyLoaded = false;

    protected static ?string $title = 'Members';

    public function form(Schema $schema): Schema
    {
        return MembersTable::form($schema);
    }

    public function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }
}
