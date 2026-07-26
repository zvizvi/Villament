<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Tables\Table;

use function Filament\Support\generate_icon_html;

/**
 * Serializes the table's action STRUCTURE (names, grouping, bulk actions).
 * Per-record action props (label/color/icon/url closures, visibility) are
 * evaluated by RecordSerializer for every row — closures never leave the
 * server. Header, toolbar and bulk actions carry no record, so their triggers
 * are evaluated once, here.
 */
final class ActionSerializer
{
    private readonly ActionTriggerSerializer $triggerSerializer;

    public function __construct()
    {
        $this->triggerSerializer = new ActionTriggerSerializer;
    }

    /**
     * @return array{record: list<array<string, mixed>>, bulk: list<array<string, mixed>>, toolbar: list<array<string, mixed>>, header: list<array<string, mixed>>}
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
                        $this->triggerSerializer->groupChildren($item),
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
                $bulk[] = ['type' => 'action', ...$this->triggerSerializer->serialize($item)];

                continue;
            }

            if (! $item instanceof ActionGroup) {
                if ($item->isVisible()) {
                    $toolbar[] = ['type' => 'action', ...$this->triggerSerializer->serialize($item)];
                }

                continue;
            }

            // Mixed groups split: bulk children join the bulk structure,
            // the rest render as a regular toolbar group.
            $bulkChildren = [];
            $toolbarChildren = [];

            foreach ($this->triggerSerializer->groupChildren($item) as $action) {
                if ($action instanceof BulkAction) {
                    $bulkChildren[] = $this->triggerSerializer->serialize($action);
                } elseif ($action->isVisible()) {
                    $toolbarChildren[] = $this->triggerSerializer->serialize($action);
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

        return [
            'record' => $record,
            'bulk' => $bulk,
            'toolbar' => $toolbar,
            'header' => $this->serializeHeaderActions($table),
        ];
    }

    /**
     * `$table->headerActions()`. Unlike the toolbar — where stock splits the
     * bulk bar out — the header renders ONE ordered row containing both kinds,
     * so the list stays in definition order and each entry says whether it is
     * bulk. Stock hides a bulk trigger with `x-show="getSelectedRecordsCount()"`
     * (see `BulkAction::getExtraAttributes()` and `BulkActionGroup`), which is
     * exactly what the `bulk` flag drives on the client.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeHeaderActions(Table $table): array
    {
        $header = [];

        foreach ($table->getHeaderActions() as $item) {
            if (! $item instanceof ActionGroup) {
                if (! $item->isVisible()) {
                    continue;
                }

                $header[] = [
                    'type' => 'action',
                    'bulk' => $item instanceof BulkAction,
                    ...$this->triggerSerializer->serialize($item),
                ];

                continue;
            }

            $children = [];

            foreach ($this->triggerSerializer->groupChildren($item) as $action) {
                if (! $action->isVisible()) {
                    continue;
                }

                $children[] = $this->triggerSerializer->serialize($action);
            }

            if ($children === []) {
                continue;
            }

            $header[] = [
                'type' => 'group',
                // A group whose every action is bulk (i.e. a `BulkActionGroup`)
                // hides as a whole, like stock's dropdown-level `x-show`.
                'bulk' => ! $item->hasNonBulkAction(),
                'label' => $this->stringable($item->getLabel()),
                'iconHtml' => $this->iconHtml($item->getIcon()),
                'actions' => $children,
            ];
        }

        return $header;
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
