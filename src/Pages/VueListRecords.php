<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Pages;

use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentVueTable\Concerns\HasVueTable;

/**
 * Drop-in replacement for ListRecords: swap the parent class on your List
 * page (or apply the HasVueTable trait to a custom one) and the table region
 * renders as a Vue island instead of the embedded Livewire table.
 */
class VueListRecords extends ListRecords
{
    use HasVueTable;
}
