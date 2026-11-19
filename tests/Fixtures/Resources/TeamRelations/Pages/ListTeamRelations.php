<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages;

use Filament\Resources\Pages\ListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\TeamRelationsResource;

class ListTeamRelations extends ListRecords
{
    protected static string $resource = TeamRelationsResource::class;
}
