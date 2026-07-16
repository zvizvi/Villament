<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Schemas;

use Filament\Schemas\Components\Component;

/**
 * Schema component rendering the Vue island mount point in place of
 * Filament's EmbeddedTable. When the table has livewire-fallback filters, it
 * also renders the page's original Livewire filter form in a hidden
 * container that the island toggles.
 */
final class VueTableIsland extends Component
{
    protected string $view = 'filament-vue-table::vue-table-island';

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config, bool $withNativeFilters = false): static
    {
        $static = app(self::class);
        $static->viewData([
            'config' => $config,
            'withNativeFilters' => $withNativeFilters,
        ]);
        $static->configure();

        return $static;
    }
}
