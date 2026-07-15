<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Schemas;

use Filament\Schemas\Components\Component;

/**
 * Schema component rendering the Vue island mount point in place of
 * Filament's EmbeddedTable.
 */
final class VueTableIsland extends Component
{
    protected string $view = 'filament-vue-table::vue-table-island';

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config): static
    {
        $static = app(self::class);
        $static->viewData(['config' => $config]);
        $static->configure();

        return $static;
    }
}
