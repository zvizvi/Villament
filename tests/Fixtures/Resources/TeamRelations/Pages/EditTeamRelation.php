<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\Pages;

use Filament\Resources\Pages\EditRecord;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamRelations\TeamRelationsResource;

class EditTeamRelation extends EditRecord
{
    protected static string $resource = TeamRelationsResource::class;
}
