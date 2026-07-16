<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects\Pages;

use Zvizvi\FilamentVueTable\Pages\VueListRecords;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects\ProjectResource;

class ListProjects extends VueListRecords
{
    protected static string $resource = ProjectResource::class;
}
