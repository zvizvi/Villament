<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\MembersTable;

/**
 * The stock twin of {@see MembersRelationManager}.
 */
class StockMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Stock members';

    public function form(Schema $schema): Schema
    {
        return MembersTable::form($schema);
    }

    public function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }
}
