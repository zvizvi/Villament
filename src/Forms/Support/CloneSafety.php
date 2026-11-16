<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Support;

use Filament\Forms\Components\Repeater;

/**
 * Whether the browser can clone an item faithfully.
 *
 * A client clone is the BLANK template's markup with the SOURCE item's state.
 * That is exact only when every component of the item is client-renderable
 * (`Renderability`): it draws everything it shows from the state. Anything
 * else — a non-native Select, a FileUpload, a nested Repeater, a `visible()`
 * closure reading the row — sends cloning back to stock's Livewire action,
 * for that repeater only.
 */
final class CloneSafety
{
    public static function isSafe(Repeater $repeater): bool
    {
        $schema = $repeater->getChildSchema();

        return ($schema !== null) && Renderability::isFullyClientRenderable($schema);
    }
}
