<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Teams\Pages;

use Filament\Resources\Pages\EditRecord;
use Zvizvi\Villament\Tests\Fixtures\Resources\Teams\TeamResource;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;
}
