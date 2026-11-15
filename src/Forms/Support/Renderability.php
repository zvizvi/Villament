<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Support;

use Closure;
use Filament\Forms\Components;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components as SchemaComponents;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;

/**
 * Decides which components of a repeater item the browser can render
 * itself, and which need the server.
 *
 * A component is CLIENT-RENDERABLE when its markup follows the item's state
 * on the client, so the blank template's markup with the row's state is
 * exactly what the server would have rendered. That needs two things:
 *
 * - A known class whose fields bind their value on the client
 *   (`wire:model` / `$entangle`). A non-native Select bakes its option label
 *   into its `x-data`, a FileUpload lists files, and a nested Repeater renders
 *   one element per nested item. Classes are matched exactly, because a
 *   subclass may render differently.
 * - No closure of the app's own in its configuration, outside a handful of
 *   lifecycle hooks that never reach the markup. `visible(fn (Get $get) => …)`,
 *   `label(fn)`, `options(fn)`, `disabled(fn)` and `extraAttributes(fn)` may
 *   all read the row's state, and only the server can evaluate them per row.
 *   Closures Filament defines itself (in `setUp()`) are exempt: they are part
 *   of what makes the class client-renderable to begin with.
 *
 * Everything else is a SERVER UNIT: rendered by the server, per row, and
 * placed by the browser into the client-rendered row (see `ClientRepeater`).
 */
final class Renderability
{
    /**
     * @var list<class-string<Component>>
     */
    private const STATE_DRIVEN_FIELDS = [
        Components\Checkbox::class,
        Components\CheckboxList::class,
        Components\ColorPicker::class,
        Components\DatePicker::class,
        Components\DateTimePicker::class,
        Components\Hidden::class,
        Components\KeyValue::class,
        Components\Radio::class,
        Components\Slider::class,
        Components\TagsInput::class,
        Components\Textarea::class,
        Components\TextInput::class,
        Components\TimePicker::class,
        Components\Toggle::class,
        Components\ToggleButtons::class,
    ];

    /**
     * Containers that only lay their children out.
     *
     * @var list<class-string<Component>>
     */
    private const LAYOUT_COMPONENTS = [
        SchemaComponents\Fieldset::class,
        SchemaComponents\Flex::class,
        SchemaComponents\Grid::class,
        SchemaComponents\Group::class,
        SchemaComponents\Section::class,
        SchemaComponents\Tabs::class,
        SchemaComponents\Tabs\Tab::class,
    ];

    /**
     * Configuration that is evaluated around the state, never into the
     * markup: hydration, dehydration, validation and option search.
     */
    private const LIFECYCLE_PROPERTIES = [
        'afterStateHydrated',
        'afterStateUpdated',
        'beforeStateDehydrated',
        'defaultState',
        'dehydrateStateUsing',
        'formatStateUsing',
        'getSearchResultsUsing',
        'isDehydrated',
        'isDehydratedWhenHidden',
        'loadStateFromRelationshipsUsing',
        'mutateDehydratedStateUsing',
        'mutateStateForValidationUsing',
        'saveRelationshipsUsing',
        'validationAttribute',
        'validationMessages',
    ];

    private static ?string $filamentRoot = null;

    /**
     * Whether every component of the item renders on the client.
     */
    public static function isFullyClientRenderable(Schema $item): bool
    {
        $plan = self::plan($item);

        return ($plan !== null) && ($plan['server'] === []);
    }

    /**
     * Splits the item into client-rendered FIELDS (they get an error variant
     * each) and SERVER units (the outermost components the client cannot
     * render; their subtrees go with them). Null when a server unit has no
     * key: without the `wire:partial="schema-component::{key}"` wrapper there
     * is nothing to place it by.
     *
     * @return array{client: list<Field>, server: list<Component>}|null
     */
    public static function plan(Schema $item): ?array
    {
        $plan = ['client' => [], 'server' => []];

        foreach ($item->getComponents(withActions: false, withHidden: true) as $component) {
            if (! self::classify($component, $plan)) {
                return null;
            }
        }

        return $plan;
    }

    /**
     * @param  array{client: list<Field>, server: list<Component>}  $plan
     */
    private static function classify(Component $component, array &$plan): bool
    {
        if (! self::isClientRenderable($component)) {
            if (blank($component->getKey())) {
                return false;
            }

            $plan['server'][] = $component;

            return true;
        }

        if ($component instanceof Field) {
            $plan['client'][] = $component;

            return true;
        }

        foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
            foreach ($childSchema->getComponents(withActions: false, withHidden: true) as $child) {
                if (! self::classify($child, $plan)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function isClientRenderable(Component $component): bool
    {
        $class = $component::class;

        $isKnown = in_array($class, self::STATE_DRIVEN_FIELDS, strict: true)
            || in_array($class, self::LAYOUT_COMPONENTS, strict: true)
            || (($class === Components\Select::class) && self::isPlainNativeSelect($component));

        if (! $isKnown || self::hasAppClosures($component)) {
            return false;
        }

        // The invalid variant is rendered with ONE message; a field that
        // lists every message renders a different element for two.
        return ! (($component instanceof Field) && $component->shouldShowAllValidationMessages());
    }

    /**
     * The component under `$item` (at any depth, hidden ones included)
     * whose absolute key is `$key`.
     */
    public static function findByKey(Schema $item, string $key): ?Component
    {
        foreach ($item->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component->getKey() === $key) {
                return $component;
            }

            if ($component instanceof Field) {
                continue;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                if ($found = self::findByKey($childSchema, $key)) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function isPlainNativeSelect(Component $select): bool
    {
        /** @var Components\Select $select */
        return $select->isNative()
            && (! $select->isSearchable())
            && (! $select->isMultiple())
            && (! $select->isHtmlAllowed())
            && blank($select->getRelationshipName());
    }

    /**
     * Whether `$component` or anything under it — its child schemas, and a
     * repeater's item schema — carries a closure the app wrote. With
     * `$includeLifecycle`, hydration and default closures count too: they
     * still evaluate per row (e.g. a default read from the enclosing row).
     *
     * @param  list<string>|null  $onlyProperties
     */
    public static function hasAppClosuresDeep(Component $component, bool $includeLifecycle = true, ?array $onlyProperties = null): bool
    {
        if (self::hasAppClosures($component, $includeLifecycle, $onlyProperties)) {
            return true;
        }

        $schemas = $component->getChildSchemas(withHidden: true);

        if (($component instanceof Repeater) && ($definition = $component->getChildSchema())) {
            $schemas[] = $definition;
        }

        foreach ($schemas as $schema) {
            if (self::schemaHasAppClosures($schema, $includeLifecycle, $onlyProperties)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $onlyProperties
     */
    public static function schemaHasAppClosures(Schema $schema, bool $includeLifecycle = true, ?array $onlyProperties = null): bool
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if (self::hasAppClosuresDeep($component, $includeLifecycle, $onlyProperties)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A closure the app wrote (anywhere outside Filament's own packages) on a
     * property that can reach the markup (or, with `$includeLifecycle`, on
     * any property at all).
     *
     * @param  list<string>|null  $onlyProperties
     */
    public static function hasAppClosures(Component $component, bool $includeLifecycle = false, ?array $onlyProperties = null): bool
    {
        $reflection = new ReflectionObject($component);

        do {
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                if (($onlyProperties !== null) && (! in_array($property->getName(), $onlyProperties, strict: true))) {
                    continue;
                }

                if ((! $includeLifecycle) && in_array($property->getName(), self::LIFECYCLE_PROPERTIES, strict: true)) {
                    continue;
                }

                if (! $property->isInitialized($component)) {
                    continue;
                }

                $value = $property->getValue($component);

                foreach (is_array($value) ? $value : [$value] as $candidate) {
                    if (($candidate instanceof Closure) && (! self::isFilamentClosure($candidate))) {
                        return true;
                    }
                }
            }
        } while ($reflection = $reflection->getParentClass());

        return false;
    }

    private static function isFilamentClosure(Closure $closure): bool
    {
        $file = (new ReflectionFunction($closure))->getFileName();

        return is_string($file) && str_starts_with($file, self::filamentRoot());
    }

    /**
     * The directory holding every `filament/*` package (`vendor/filament/`).
     */
    private static function filamentRoot(): string
    {
        return self::$filamentRoot ??= dirname((string) (new ReflectionClass(Repeater::class))->getFileName(), 4).DIRECTORY_SEPARATOR;
    }
}
