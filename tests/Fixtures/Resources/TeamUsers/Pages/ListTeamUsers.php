<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\TeamUserResource;

class ListTeamUsers extends VillamentListRecords
{
    protected static string $resource = TeamUserResource::class;
}
