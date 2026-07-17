<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Projects\Pages;

use Filament\Resources\Pages\ViewRecord;
use Zvizvi\Villament\Tests\Fixtures\Resources\Projects\ProjectResource;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
}
