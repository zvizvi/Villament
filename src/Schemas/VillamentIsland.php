<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Schemas;

use Closure;
use Filament\Schemas\Components\Component;

/**
 * Schema component rendering the Vue island mount point in place of
 * Filament's EmbeddedTable. When the table has livewire-fallback filters, it
 * also renders the page's original Livewire filter form in a hidden
 * container that the island toggles.
 */
final class VillamentIsland extends Component
{
    protected string $view = 'villament::villament-island';

    /**
     * @param  Closure(): array<string, mixed>  $config  evaluated when the island renders
     */
    public static function make(Closure $config): static
    {
        $static = app(self::class);
        $static->viewData(function () use ($config): array {
            $config = $config();

            return [
                'config' => $config,
                'withNativeFilters' => (bool) ($config['schema']['hasFilters'] ?? false),
                'withNativeColumnManager' => (bool) ($config['schema']['hasColumnManager'] ?? false),
                'withNativeHeader' => (bool) ($config['schema']['header']['hasCustomView'] ?? false),
                'withColumnFilterSlots' => ($config['columnFilterSlots'] ?? []) !== [],
                'renderHooks' => $config['renderHooks'] ?? [],
            ];
        });
        $static->configure();

        return $static;
    }
}
