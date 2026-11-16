<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Projects\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\Villament\RelationManagers\VillamentRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\ProjectResource;

/**
 * A relation manager on a TENANT panel. Its record URL is a tenant-scoped
 * `getUrl()`, which needs `{tenant}` when the endpoint evaluates it.
 */
class TeamMembersRelationManager extends VillamentRelationManager
{
    protected static string $relationship = 'teamMembers';

    protected static ?bool $isLazyLoaded = false;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')->sortable()])
            ->defaultSort('name')
            ->recordUrl(fn (User $record): string => ProjectResource::getUrl('index'));
    }
}
