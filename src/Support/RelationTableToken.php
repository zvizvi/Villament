<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use JsonException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Names a relationship table for the relation endpoint: which panel,
 * tenant, resource, page, relation manager (with the properties it was
 * mounted with) and owner record. Issued server-side when the island renders, and
 * encrypted with the app key — so it is authenticated too: the client can
 * carry it, never forge or edit it.
 *
 * The token exists for speed. Naming the manager in the URL meant
 * re-deriving it on every request (a headless instance of the host page,
 * its `getRelationManagers()`, a policy check per manager) and again on
 * every render, just to learn what the server already knew when it drew the
 * island. The token carries that answer. What it does NOT carry is
 * authorization: the endpoint still resolves the owner record through the
 * page and runs the page's and the manager's own access checks on every
 * request, so a token outliving a permission is refused like any request.
 *
 * JSON, not `serialize()`: nothing decoded from a request is ever
 * unserialized.
 */
final class RelationTableToken
{
    private const VERSION = 1;

    public function __construct(private readonly StringEncrypter $encrypter) {}

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     * @param  class-string  $pageClass
     * @param  class-string<RelationManager>|null  $relationManagerClass  null: the page is a ManageRelatedRecords page
     * @param  array<string, mixed>  $properties
     */
    public function issue(
        string $panelId,
        string $resourceClass,
        string $pageClass,
        ?string $relationManagerClass,
        array $properties,
        int|string $ownerKey,
        int|string|null $parentKey,
        int|string|null $tenantKey = null,
    ): string {
        return $this->encrypter->encryptString(json_encode([
            'v' => self::VERSION,
            'panel' => $panelId,
            'resource' => $resourceClass,
            'page' => $pageClass,
            'manager' => $relationManagerClass,
            'properties' => $properties,
            'owner' => $ownerKey,
            'parent' => $parentKey,
            // The tenant the island was rendered under. A tenant-scoped
            // resource would not resolve the owner under another tenant
            // anyway; this also holds for one that is not scoped.
            'tenant' => $tenantKey,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{panel: string, resource: string, page: string, manager: ?string, properties: array<string, mixed>, owner: int|string, parent: int|string|null, tenant: int|string|null}|null
     */
    public function read(string $token): ?array
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // Tampered with, truncated, or sealed under a key this app no
            // longer has (rotate with APP_PREVIOUS_KEYS to keep open pages).
            return null;
        }

        if (
            ! is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ! is_string($payload['panel'] ?? null)
            || ! is_string($payload['resource'] ?? null)
            || ! is_string($payload['page'] ?? null)
            || ! (is_string($payload['manager'] ?? null) || ($payload['manager'] ?? null) === null)
            || ! is_array($payload['properties'] ?? null)
            || ! (is_int($payload['owner'] ?? null) || is_string($payload['owner'] ?? null))
            || ! (is_int($payload['parent'] ?? null) || is_string($payload['parent'] ?? null) || ($payload['parent'] ?? null) === null)
            || ! (is_int($payload['tenant'] ?? null) || is_string($payload['tenant'] ?? null) || ($payload['tenant'] ?? null) === null)
        ) {
            return null;
        }

        /** @var array{panel: string, resource: string, page: string, manager: ?string, properties: array<string, mixed>, owner: int|string, parent: int|string|null, tenant: int|string|null} */
        return $payload;
    }

    /**
     * The properties a relation manager was mounted with beyond Filament's
     * own: a `RelationManagerConfiguration`'s (`Manager::make([...])`), or a
     * plugin's (`activeLocale`). Those are exactly the public properties the
     * subclass declares that `RelationManager` does not. Only plain values
     * travel; anything else (a model, an object) is left at its default.
     *
     * @return array<string, mixed>
     */
    public function mountedProperties(RelationManager $component): array
    {
        static $baseProperties = null;

        $baseProperties ??= array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(RelationManager::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        $properties = [];

        foreach ((new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ($property->isStatic() || in_array($name, $baseProperties, true) || ! $property->isInitialized($component)) {
                continue;
            }

            $value = $property->getValue($component);

            if (is_scalar($value) || $value === null || (is_array($value) && $this->isPlain($value))) {
                $properties[$name] = $value;
            }
        }

        return $properties;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isPlain(array $value): bool
    {
        foreach ($value as $item) {
            if (! (is_scalar($item) || $item === null || (is_array($item) && $this->isPlain($item)))) {
                return false;
            }
        }

        return true;
    }
}
