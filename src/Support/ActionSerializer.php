<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Support\View\Components\DropdownComponent\ItemComponent;
use Filament\Support\View\Components\DropdownComponent\ItemComponent\IconComponent;
use Filament\Tables\Table;

use function Filament\Support\generate_icon_html;
use function Filament\Support\get_component_color_classes;

/**
 * Serializes the table's action STRUCTURE (names, grouping, bulk actions).
 * Per-record action props (label/color/icon/url closures, visibility) are
 * evaluated by RecordSerializer for every row — closures never leave the
 * server. Bulk actions carry no record, so their props are evaluated here.
 */
final class ActionSerializer
{
    /**
     * @return array{record: list<array<string, mixed>>, bulk: list<array<string, mixed>>}
     */
    public function serializeStructure(Table $table): array
    {
        $record = [];

        foreach ($table->getRecordActions() as $item) {
            if ($item instanceof ActionGroup) {
                $record[] = [
                    'type' => 'group',
                    'label' => $this->stringable($item->getLabel()),
                    'iconHtml' => $this->iconHtml($item->getIcon()),
                    'actions' => array_values(array_map(
                        fn (Action $action): string => $action->getName(),
                        $item->getFlatActions(),
                    )),
                ];

                continue;
            }

            $record[] = [
                'type' => 'action',
                'name' => $item->getName(),
            ];
        }

        $bulk = [];
        $toolbar = [];

        foreach ($table->getToolbarActions() as $item) {
            if ($item instanceof BulkAction) {
                $bulk[] = ['type' => 'action', ...$this->serializeBulkAction($item)];

                continue;
            }

            if (! $item instanceof ActionGroup) {
                if ($item->isVisible()) {
                    $toolbar[] = ['type' => 'action', ...$this->serializeToolbarAction($item)];
                }

                continue;
            }

            // Mixed groups split: bulk children join the bulk structure,
            // the rest render as a regular toolbar group.
            $bulkChildren = [];
            $toolbarChildren = [];

            foreach ($item->getFlatActions() as $action) {
                if ($action instanceof BulkAction) {
                    $bulkChildren[] = $this->serializeBulkAction($action, grouped: true);
                } elseif ($action->isVisible()) {
                    $toolbarChildren[] = $this->serializeToolbarAction($action, grouped: true);
                }
            }

            if ($bulkChildren !== []) {
                $bulk[] = [
                    'type' => 'group',
                    'label' => $this->stringable($item->getLabel()),
                    'iconHtml' => $this->iconHtml($item->getIcon()),
                    'actions' => $bulkChildren,
                ];
            }

            if ($toolbarChildren !== []) {
                $toolbar[] = [
                    'type' => 'group',
                    'label' => $this->stringable($item->getLabel()),
                    'iconHtml' => $this->iconHtml($item->getIcon()),
                    'actions' => $toolbarChildren,
                ];
            }
        }

        return ['record' => $record, 'bulk' => $bulk, 'toolbar' => $toolbar];
    }

    /**
     * Toolbar (non-bulk) actions carry no record context — statically
     * evaluated, like bulk actions, plus their optional URL behavior.
     *
     * @return array<string, mixed>
     */
    private function serializeToolbarAction(Action $action, bool $grouped = false): array
    {
        $color = $action->getColor();
        $color = is_string($color) ? $color : null;
        $url = $action->getUrl();
        $newTab = filled($url) && $action->shouldOpenUrlInNewTab();

        return [
            'name' => $action->getName(),
            'label' => $this->stringable($action->getLabel()),
            'color' => $color,
            ...$this->colorClasses($color, $grouped),
            'iconHtml' => $this->iconHtml($action->getIcon(default: $action->getTable() !== null ? $action->getTableIcon() : null)),
            'disabled' => $action->isDisabled(),
            'url' => $url,
            'newTab' => $newTab,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBulkAction(BulkAction $action, bool $grouped = false): array
    {
        $color = $action->getColor();
        $color = is_string($color) ? $color : null;

        return [
            'name' => $action->getName(),
            'label' => $this->stringable($action->getLabel()),
            'color' => $color,
            ...$this->colorClasses($color, $grouped),
            'iconHtml' => $this->iconHtml($action->getIcon()),
        ];
    }

    /**
     * The Filament-computed color classes for an action, chosen by render
     * context: a standalone action is a `fi-btn` (ButtonComponent — includes
     * the background/text shades), a grouped one is a dropdown list item
     * (ItemComponent — text shades only, gray by default). A null/gray color
     * yields empty strings so the element keeps its default styling.
     *
     * @return array{colorClasses: string, iconColorClasses: string}
     */
    private function colorClasses(?string $color, bool $grouped): array
    {
        if ($grouped) {
            // Dropdown items default to gray (HasDefaultGrayColor) → no classes.
            return [
                'colorClasses' => implode(' ', get_component_color_classes(ItemComponent::class, $color)),
                'iconColorClasses' => implode(' ', get_component_color_classes(IconComponent::class, $color)),
            ];
        }

        // Standalone action buttons default to primary (the blade prop default).
        return [
            'colorClasses' => implode(' ', get_component_color_classes(ButtonComponent::class, $color ?? 'primary')),
            'iconColorClasses' => '',
        ];
    }

    private function iconHtml(mixed $icon): ?string
    {
        if (blank($icon)) {
            return null;
        }

        return generate_icon_html($icon)?->toHtml();
    }

    private function stringable(mixed $value): ?string
    {
        return blank($value) ? null : (string) $value;
    }
}
