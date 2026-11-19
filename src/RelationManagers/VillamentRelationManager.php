<?php

declare(strict_types=1);

namespace Zvizvi\Villament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Zvizvi\Villament\Concerns\HasVillamentRelationTable;

/**
 * Drop-in replacement for RelationManager: swap the parent class on your
 * relation manager (or apply the HasVillamentRelationTable trait) and its
 * table renders as a Vue island instead of the embedded Livewire table.
 */
class VillamentRelationManager extends RelationManager
{
    use HasVillamentRelationTable;
}
