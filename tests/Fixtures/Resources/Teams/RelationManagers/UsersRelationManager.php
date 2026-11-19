<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Teams\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Zvizvi\Villament\RelationManagers\VillamentRelationManager;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\TeamUserResource;

/**
 * A relation manager whose related resource is NESTED under the owner's
 * resource (`/teams/{team}/users`): its create, edit and record URLs are the
 * nested resource's, which Filament builds by guessing `{team}` from the host
 * page's `{record}`.
 */
class UsersRelationManager extends VillamentRelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $relatedResource = TeamUserResource::class;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }
}
