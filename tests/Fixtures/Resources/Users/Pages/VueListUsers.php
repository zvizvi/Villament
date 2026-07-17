<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages;

use Zvizvi\Villament\Pages\VillamentListRecords;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

class VueListUsers extends VillamentListRecords
{
    protected static string $resource = UserResource::class;
}
