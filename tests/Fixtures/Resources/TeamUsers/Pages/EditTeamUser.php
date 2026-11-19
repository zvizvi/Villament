<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\Pages;

use Filament\Resources\Pages\EditRecord;
use Zvizvi\Villament\Tests\Fixtures\Resources\TeamUsers\TeamUserResource;

class EditTeamUser extends EditRecord
{
    protected static string $resource = TeamUserResource::class;
}
