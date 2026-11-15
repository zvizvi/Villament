<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Support;

use Filament\Forms\Components;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components as SchemaComponents;
use Filament\Schemas\Components\Component;

/**
 * Whether the browser can clone an item faithfully.
 *
 * A client clone is the BLANK template's markup with the SOURCE item's state.
 * That is exact only when every field draws its value from the state on the
 * client (`wire:model` / `$entangle`). A field whose server-rendered HTML
 * already depends on its value is not: a non-native Select bakes its
 * `initialOptionLabel` in, a FileUpload lists its files, and a nested
 * Repeater or Builder renders one element per item, keyed by the item. Any
 * such field — and any component this list does not know — sends cloning
 * back to stock's Livewire action, for that repeater only.
 *
 * Classes are matched exactly, not by `instanceof`: a subclass may render
 * differently, so it has to be listed on its own.
 */
final class CloneSafety
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

    public static function isSafe(Repeater $repeater): bool
    {
        $schema = $repeater->getChildSchema();

        if ($schema === null) {
            return false;
        }

        foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $component) {
            if (! self::isSafeComponent($component)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeComponent(Component $component): bool
    {
        $class = $component::class;

        if (in_array($class, self::LAYOUT_COMPONENTS, strict: true)) {
            return true;
        }

        if (in_array($class, self::STATE_DRIVEN_FIELDS, strict: true)) {
            return true;
        }

        if ($component instanceof Components\Select && ($class === Components\Select::class)) {
            // Only the plain native <select>: its options are in the markup
            // and `wire:model` picks the value.
            return $component->isNative()
                && (! $component->isSearchable())
                && (! $component->isMultiple())
                && (! $component->isHtmlAllowed())
                && (! $component->getRelationshipName());
        }

        return false;
    }
}
