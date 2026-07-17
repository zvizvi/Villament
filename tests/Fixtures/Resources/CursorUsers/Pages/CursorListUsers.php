<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\CursorUserResource;

class CursorListUsers extends VillamentListRecords
{
    protected static string $resource = CursorUserResource::class;
}
