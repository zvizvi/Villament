<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages;

use Zvizvi\FilamentVueTable\Pages\VueListRecords;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\UserResource;

class VueListUsers extends VueListRecords
{
    protected static string $resource = UserResource::class;
}
