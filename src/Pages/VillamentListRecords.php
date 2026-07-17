<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Pages;

use Filament\Resources\Pages\ListRecords;
use Zvizvi\Villament\Concerns\HasVillamentTable;

/**
 * Drop-in replacement for ListRecords: swap the parent class on your List
 * page (or apply the HasVillamentTable trait to a custom one) and the table region
 * renders as a Vue island instead of the embedded Livewire table.
 */
class VillamentListRecords extends ListRecords
{
    use HasVillamentTable;
}
