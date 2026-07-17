<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Projects\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\ProjectResource;

class ListProjects extends VillamentListRecords
{
    protected static string $resource = ProjectResource::class;
}
