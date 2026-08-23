<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

use Filament\Forms\Components\Repeater;
use Zvizvi\Villament\Forms\Concerns\HasClientSideItems;

/**
 * A `Repeater` whose add / clone / delete / move / drag-reorder run in the
 * browser instead of through Livewire.
 *
 * Drop-in: `VillamentRepeater::make('members')` takes every `Repeater`
 * method. When a feature it cannot reproduce client-side is used, it degrades
 * to stock behaviour on its own — see `HasClientSideItems::hasClientSideItems()`
 * — or turn it off explicitly with `->clientSideItems(false)`.
 *
 * Unlike the table island there is NO Vue here and no `wire:ignore` region:
 * the fields are Filament's own server-rendered markup, so `wire:model`,
 * validation errors, action modals and every Alpine field component keep
 * working untouched.
 */
class VillamentRepeater extends Repeater
{
    use HasClientSideItems;
}
