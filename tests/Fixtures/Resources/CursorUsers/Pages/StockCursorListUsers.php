<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\Pages;

use Filament\Resources\Pages\ListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\CursorUsers\CursorUserResource;

class StockCursorListUsers extends ListRecords
{
    protected static string $resource = CursorUserResource::class;
}
