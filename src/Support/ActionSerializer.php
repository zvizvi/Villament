<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Tables\Table;

use function Filament\Support\generate_icon_html;

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

        foreach ($table->getToolbarActions() as $item) {
            if ($item instanceof BulkAction) {
                $bulk[] = ['type' => 'action', ...$this->serializeBulkAction($item)];

                continue;
            }

            if (! $item instanceof ActionGroup) {
                // Non-bulk toolbar actions are out of scope for the island
                // (they belong to the stock toolbar region) — see DECISIONS.md.
                continue;
            }

            $bulkChildren = [];

            foreach ($item->getFlatActions() as $action) {
                if ($action instanceof BulkAction) {
                    $bulkChildren[] = $this->serializeBulkAction($action);
                }
            }

            if ($bulkChildren === []) {
                continue;
            }

            $bulk[] = [
                'type' => 'group',
                'label' => $this->stringable($item->getLabel()),
                'iconHtml' => $this->iconHtml($item->getIcon()),
                'actions' => $bulkChildren,
            ];
        }

        return ['record' => $record, 'bulk' => $bulk];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBulkAction(BulkAction $action): array
    {
        $color = $action->getColor();

        return [
            'name' => $action->getName(),
            'label' => $this->stringable($action->getLabel()),
            'color' => is_string($color) ? $color : null,
            'iconHtml' => $this->iconHtml($action->getIcon()),
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
