<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\Assets;

use Filament\Support\Assets\Js;

/**
 * A JS asset versioned by its own CONTENT hash rather than the composer
 * package version. Filament's default `?v=` is the package version, which is
 * stable for `dev-main` installs — so a rebuilt bundle is never re-fetched by
 * the browser. Content hashing busts the cache automatically on every build
 * (dev and production alike, and is safe for immutable far-future caching).
 */
final class ContentHashedJs extends Js
{
    public function getVersion(): string
    {
        $path = $this->getPath();

        if (is_string($path) && is_file($path)) {
            $hash = @hash_file('xxh128', $path);

            if ($hash !== false) {
                return $hash;
            }
        }

        return parent::getVersion();
    }
}
