<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Shell;

use Filament\Facades\Filament;

/**
 * Names a kept piece of the panel shell. Livewire matches a kept element to
 * its place in the next page by this key alone, so the key holds everything
 * the shell's markup depends on that can change between two SPA pages:
 * panel, tenant, locale and user. A different key means the browser's copy
 * is not reused and the shell renders in full. `villament.shell.version`
 * lets an app retire every kept shell at once (a deploy, a navigation change).
 */
final class ShellKey
{
    public static function for(string $part): string
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        $hash = substr(hash('xxh128', implode('|', [
            $panel->getId(),
            (string) Filament::getTenant()?->getKey(),
            app()->getLocale(),
            (string) auth()->id(),
            (string) config('villament.shell.version', ''),
        ])), 0, 16);

        return "villament.shell.{$part}.{$hash}";
    }

    /**
     * A page request made by Livewire's `wire:navigate`, which sends
     * `X-Livewire-Navigate` with its fetch (and its hover prefetch). A
     * Livewire update never carries it.
     */
    public static function isNavigateRequest(): bool
    {
        return request()->hasHeader('X-Livewire-Navigate');
    }
}
