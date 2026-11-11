<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Teams\Pages;

use Filament\Resources\Pages\ListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\Teams\TeamResource;

class ListTeams extends ListRecords
{
    protected static string $resource = TeamResource::class;
}
