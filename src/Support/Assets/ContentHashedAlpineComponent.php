<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support\Assets;

use Filament\Support\Assets\AlpineComponent;

/**
 * An Alpine component asset versioned by its content hash, for the same
 * reason as `ContentHashedJs`: a rebuilt bundle must bust the browser cache
 * on `dev-main` installs too.
 */
final class ContentHashedAlpineComponent extends AlpineComponent
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
