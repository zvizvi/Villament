<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers\Pages;

use Zvizvi\FilamentVueTable\Pages\VueListRecords;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers\CursorUserResource;

class CursorListUsers extends VueListRecords
{
    protected static string $resource = CursorUserResource::class;
}
