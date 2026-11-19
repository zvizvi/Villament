<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Pages;

use Filament\Resources\Pages\ManageRelatedRecords;
use Zvizvi\Villament\Concerns\HasVillamentRelationTable;

/**
 * Drop-in replacement for ManageRelatedRecords: swap the parent class on
 * your page (or apply the HasVillamentRelationTable trait) and its table
 * renders as a Vue island instead of the embedded Livewire table.
 */
class VillamentManageRelatedRecords extends ManageRelatedRecords
{
    use HasVillamentRelationTable;
}
