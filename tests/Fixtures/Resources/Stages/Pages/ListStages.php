<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Stages\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\Stages\StageResource;

class ListStages extends VillamentListRecords
{
    protected static string $resource = StageResource::class;
}
