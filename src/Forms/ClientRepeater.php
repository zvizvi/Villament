<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireManager;
use Zvizvi\Villament\Forms\Support\ActionBehavior;
use Zvizvi\Villament\Forms\Support\CloneSafety;
use Zvizvi\Villament\Forms\Support\HtmlFragments;
use Zvizvi\Villament\Forms\Support\Renderability;

/**
 * A `Repeater` whose structural actions (add, add between, delete, clone,
 * move up / down, drag reorder) run in the browser without a Livewire
 * request. Collapsing was already client-side in stock.
 *
 * The server renders ONE blank item under a placeholder key, through the same
 * `fill()` stock's add action runs, then removes the placeholder from the
 * state before the snapshot is taken. That item's HTML and state ship inside
 * an inert `<template>`; the Alpine component copies it, swaps every
 * placeholder for a fresh UUID and writes the new item list with a deferred
 * `$wire.set()`, which the next request carries to the server.
 *
 * The items' CONTENT renders in the browser too (client rendering). The
 * server keeps rendering each item's shell (header, labels, actions: all
 * stock, all morphed), but the content element is `wire:ignore`d and, on
 * Livewire requests, left empty: the browser fills it from the template and
 * the row's state. What the browser cannot render — a searchable Select, a
 * FileUpload, a nested repeater, a component with a `visible(fn)` closure,
 * see `Renderability` — the server renders per row, into ONE slot per row
 * (`<template data-villament-repeater-slot>`), and the browser places each
 * of those units into its spot in the row. Validation errors of the
 * client-rendered fields come from `$wire.$errors`, drawn with the exact
 * error markup the server rendered once, into the template.
 *
 * Anything the browser cannot reproduce exactly keeps the stock Livewire
 * path: an action the developer customised with behaviour (confirmation,
 * modal, form, lifecycle hooks, a replaced `action()`), `extraItemActions()`,
 * a `live()` repeater or one with `afterStateUpdated()`, the `table()`
 * layout, `generateUuidUsing(false)`, and cloning an item that is not fully
 * client-renderable. See DECISIONS.md.
 */
class ClientRepeater extends Repeater
{
    public const TEMPLATE_ATTRIBUTE = 'data-villament-repeater-template';

    protected bool $isRenderingClientTemplate = false;

    protected bool $isRenderingLabelBetweenTemplate = false;

    protected bool $hasPreparedClientActions = false;

    /**
     * Set while the item shells render: `getItems()` hands out item schemas
     * without components, so no field renders.
     */
    protected bool $isRenderingItemShells = false;

    protected bool|Closure $hasClientRendering = true;

    /**
     * What the template render found about the item, for this render: the
     * server units' keys and the client fields, relative to the item.
     *
     * @var array{server: list<string>, fields: list<array<string, mixed>>}|null
     */
    protected ?array $renderPlan = null;

    /**
     * `table()` with client rendering: where the component cells sit among
     * the template row's cells (after its reorder cell, before its actions
     * cell), and how many there are.
     *
     * @var array{start: int, count: int}|null
     */
    protected ?array $tableCells = null;

    /**
     * Paths (relative to this repeater's item) of the nested
     * `ClientRepeater`s whose full template this repeater's own template
     * carries. A nested instance in a real row then ships a reference to it
     * instead of its own copy.
     *
     * @var array<string, true>
     */
    protected array $templateSources = [];

    /**
     * The error message the template's invalid variants are rendered with;
     * the browser swaps in the real one.
     */
    public const ERROR_SENTINEL = 'villament-repeater-error-sentinel';

    /**
     * Which structural actions run in the browser for this render.
     *
     * @var array<string, bool>
     */
    protected array $clientActions = [];

    /**
     * The client-side structure is opt-out per component.
     */
    protected bool|Closure $hasClientStructure = true;

    public function clientStructure(bool|Closure $condition = true): static
    {
        $this->hasClientStructure = $condition;

        return $this;
    }

    /**
     * Client rendering of the items' content is on by default; off, the
     * server renders every item (the structure stays client-side).
     */
    public function clientRendering(bool|Closure $condition = true): static
    {
        $this->hasClientRendering = $condition;

        return $this;
    }

    /**
     * Whether the items' content renders in the browser for this render.
     * Known only once the template has rendered (it classifies the item).
     */
    public function isClientRendered(): bool
    {
        return ($this->renderPlan !== null) && (bool) $this->evaluate($this->hasClientRendering);
    }

    /**
     * @return array<Schema>
     */
    public function getItems(): array
    {
        $items = parent::getItems();

        if (! $this->isRenderingItemShells) {
            return $items;
        }

        return array_map(
            fn (Schema $item): Schema => $item->getClone()->components([]),
            $items,
        );
    }

    /**
     * Whether whole items are rendered on this request. A page load and a
     * `wire:navigate` visit render everything, so the first paint is
     * complete; Livewire's own update requests only render the shells.
     */
    protected function shouldRenderItemContent(): bool
    {
        return ! app(LivewireManager::class)->isLivewireRequest();
    }

    /**
     * Whether this repeater's structure changes run in the browser at all.
     * False means the component behaves exactly like a stock `Repeater`.
     */
    public function hasClientStructure(): bool
    {
        if (! $this->evaluate($this->hasClientStructure)) {
            return false;
        }

        // Integer keys: a JS object would re-sort them numerically.
        if ($this->generateUuidUsing === false) {
            return false;
        }

        // Stock's actions call `callAfterStateUpdated()` and, on a live
        // repeater, other fields react to the new state. The browser can do
        // neither, so these keep the server path for every action.
        if ($this->isLive() || filled($this->afterStateUpdated)) {
            return false;
        }

        if ($this->isDisabled()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    public function getClientActions(): array
    {
        $this->prepareClientActions();

        return $this->clientActions;
    }

    public function isAddable(): bool
    {
        if ($this->isRenderingLabelBetweenTemplate) {
            return false;
        }

        if ($this->isRenderingClientTemplate) {
            // The template must carry the add controls even when the list
            // is full right now: the browser shows them again once an item
            // is deleted. `maxItems` is enforced client-side instead.
            return (! $this->isDisabled()) && (bool) $this->evaluate($this->isAddable);
        }

        return parent::isAddable();
    }

    public function isCloneable(): bool
    {
        if ($this->isRenderingClientTemplate) {
            return (! $this->isDisabled()) && (bool) $this->evaluate($this->isCloneable);
        }

        return parent::isCloneable();
    }

    public function toEmbeddedHtml(): string
    {
        if (! $this->hasClientStructure()) {
            return parent::toEmbeddedHtml();
        }

        $this->prepareClientActions();

        if (! array_filter($this->clientActions)) {
            return parent::toEmbeddedHtml();
        }

        // The template first: it classifies the item, which decides how the
        // items themselves render.
        $template = $this->renderClientTemplate();

        $html = $this->isClientRendered() ? $this->renderItemShells() : parent::toEmbeddedHtml();
        $html = HtmlFragments::insertAfterOpeningTag($html, 'data-reorder-owner', $template);

        // A Livewire response is only ever morphed, and whitespace-only text
        // nodes render the same however long they are: on those requests
        // the indentation of Filament's PHP-echoed markup is dead weight.
        // A page load stays byte-for-byte stock.
        return ($this->isClientRendered() && (! $this->shouldRenderItemContent()))
            ? HtmlFragments::collapseWhitespace($html)
            : $html;
    }

    /**
     * The stock render, with every item's content element `wire:ignore`d
     * (the browser owns it) and the row's slot in front of it. On Livewire
     * requests the content is not rendered at all and is marked pending.
     */
    protected function renderItemShells(): string
    {
        $isLight = ! $this->shouldRenderItemContent();
        $keys = array_map('strval', array_keys(parent::getItems()));

        try {
            $this->isRenderingItemShells = $isLight;

            $html = parent::toEmbeddedHtml();
        } finally {
            $this->isRenderingItemShells = false;
        }

        if ($this->isTable()) {
            return $this->decorateTableShells($html, $keys, $isLight);
        }

        $contentClass = $this->isSimple() ? 'class="fi-fo-simple-repeater-item-content"' : 'class="fi-fo-repeater-item-content"';
        $attributes = ' wire:ignore'.($isLight ? ' data-villament-repeater-pending' : '');

        foreach ($keys as $key) {
            $itemStart = strpos($html, 'x-sortable-item="'.e($key).'"');

            if ($itemStart === false) {
                continue;
            }

            $slot = $this->renderRowSlot($key, renderedHtml: $isLight ? null : (HtmlFragments::element($html, 'x-sortable-item="'.e($key).'"', 'li') ?? ''));

            $html = HtmlFragments::decorateTag($html, $itemStart, $contentClass, $attributes, before: $slot);
        }

        return $html;
    }

    /**
     * `table()`'s shells: a row has no content element, its component cells
     * are. On a page load each cell gets `wire:ignore`; on Livewire requests
     * the row has none, and gets one `wire:ignore`d, pending placeholder per
     * cell, after its reorder cell. The row's slot leads the row (a
     * `<template>` is allowed in a `<tr>`).
     *
     * @param  list<string>  $keys
     */
    protected function decorateTableShells(string $html, array $keys, bool $isLight): string
    {
        $hasReorderColumn = (count($keys) > 1) && ($this->isReorderableWithButtons() || $this->isReorderableWithDragAndDrop());
        $count = $this->tableCells['count'] ?? 0;

        foreach ($keys as $key) {
            $needle = 'x-sortable-item="'.e($key).'"';
            $row = HtmlFragments::element($html, $needle, 'tr');
            $position = $row === null ? false : strpos($html, $row);

            if (($row === null) || ($position === false)) {
                continue;
            }

            $cells = $this->tableCells($row);
            $slot = $this->renderRowSlot($key, renderedHtml: $isLight ? null : $row);

            if ($isLight) {
                $at = $hasReorderColumn && isset($cells[0]) ? $cells[0][1] : (int) HtmlFragments::openingTagEnd($row);
                $placeholders = implode('', array_map(
                    fn (int $index): string => '<td '.self::cellAttributes($key, $index).' data-villament-repeater-pending></td>',
                    range(0, max(0, $count - 1)),
                ));

                $decorated = substr($row, 0, $at).($count ? $placeholders : '').substr($row, $at);
            } else {
                $decorated = self::decorateCells($row, array_slice($cells, $hasReorderColumn ? 1 : 0, $count), $key);
            }

            $openingEnd = (int) HtmlFragments::openingTagEnd($decorated);
            $decorated = substr($decorated, 0, $openingEnd).$slot.substr($decorated, $openingEnd);

            $html = substr_replace($html, $decorated, $position, strlen($row));
        }

        return $html;
    }

    /**
     * The `<td>` children of a `table()` row, as offsets into it.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function tableCells(string $row): array
    {
        return array_values(array_filter(
            HtmlFragments::children($row),
            fn (array $child): bool => (bool) preg_match('/\G<td[\s>]/', $row, offset: $child[0]),
        ));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $cells
     */
    protected static function decorateCells(string $row, array $cells, string $key): string
    {
        // From the last, so earlier offsets stay valid.
        foreach (array_reverse($cells, preserve_keys: true) as $index => [$start]) {
            $row = substr($row, 0, $start + 3).' '.self::cellAttributes($key, $index).substr($row, $start + 3);
        }

        return $row;
    }

    /**
     * A component cell is the browser's (`wire:ignore`), and keyed: the
     * morph must match the row's cells by key, not by position — the
     * reorder cell before them comes and goes with the row count.
     */
    protected static function cellAttributes(string $key, int $index): string
    {
        return 'wire:ignore wire:key="villament-repeater-cell.'.e($key).'.'.$index.'"';
    }

    /**
     * Stock's `$hasActionsColumn`, optionally as the template renders it
     * (clone allowed past `maxItems`).
     */
    protected function hasTableActionsColumn(bool $ignoringMaxItems = false): bool
    {
        $isCloneable = $ignoringMaxItems
            ? ((! $this->isDisabled()) && (bool) $this->evaluate($this->isCloneable))
            : $this->isCloneable();

        return count($this->getExtraItemActions()) || $isCloneable || $this->isDeletable();
    }

    /**
     * The row's ONE slot: every server unit of the item, rendered for this
     * row through Filament's own `toSchemaHtml()` (the component with its
     * `wire:partial` wrapper, exactly what the item's own render emits),
     * and a hash per unit. The browser replaces a unit only when its hash
     * changed. Keyed by the hashes, so the morph replaces the slot then.
     *
     * On a page load the content already holds the units (`$renderedHtml`):
     * the slot then ships only their hashes, cut from that render.
     */
    protected function renderRowSlot(string $key, ?string $renderedHtml = null): string
    {
        $units = $this->renderPlan['server'] ?? [];
        $schema = ($units === [] || $renderedHtml !== null) ? null : $this->getChildSchema($key);

        if (($units === []) || (($renderedHtml === null) && ($schema === null))) {
            return '';
        }

        $hashes = [];
        $content = '';

        foreach ($units as $unit) {
            $partial = "schema-component::{$this->getKey()}.{$key}.{$unit}";

            $html = ($renderedHtml !== null)
                ? HtmlFragments::elementOpenedBy($renderedHtml, 'wire:partial="'.$partial.'"')
                : Renderability::findByKey($schema, "{$this->getKey()}.{$key}.{$unit}")?->toSchemaHtml();

            if (blank($html)) {
                continue;
            }

            // The browser owns what it inserts, like the template's content.
            $html = HtmlFragments::collapseWhitespace($html);
            $hashes[$partial] = self::unitHash($html, $key);

            if ($renderedHtml === null) {
                $content .= $html;
            }
        }

        if ($hashes === []) {
            return '';
        }

        $json = json_encode($hashes, JSON_THROW_ON_ERROR);

        return '<template data-villament-repeater-slot data-villament-repeater-hashes="'.e($json).'" wire:key="villament-repeater-slot.'.e($key).'.'.md5($json).'">'.$content.'</template>';
    }

    /**
     * A server unit's identity across renders: its markup with the row key
     * factored out, so the template's provisional unit (under the
     * placeholder) and a row's real one compare equal when they are.
     */
    public static function unitHash(string $html, string $key): string
    {
        // Trimmed: `toSchemaHtml()` pads its output, a cut element is exact.
        return md5(str_replace($key, '{key}', trim(HtmlFragments::collapseWhitespace($html))));
    }

    /**
     * Decides, once per render, which structural actions are client-side,
     * and rewires those to the Alpine component: the Livewire click handler
     * goes, a `data-villament-repeater-action` naming the operation comes in,
     * which the component's delegated click listener reads. The class list
     * and every other attribute stay exactly as stock renders them.
     */
    protected function prepareClientActions(): void
    {
        if ($this->hasPreparedClientActions) {
            return;
        }

        $this->hasPreparedClientActions = true;

        if (! $this->hasClientStructure()) {
            $this->clientActions = [];

            return;
        }

        $operations = [
            'add' => $this->getAddActionName(),
            'addBetween' => $this->getAddBetweenActionName(),
            'clone' => $this->getCloneActionName(),
            'delete' => $this->getDeleteActionName(),
            'moveUp' => $this->getMoveUpActionName(),
            'moveDown' => $this->getMoveDownActionName(),
            'reorder' => $this->getReorderActionName(),
        ];

        foreach ($operations as $operation => $name) {
            $action = $this->getAction($name);

            $isClient = $action instanceof Action
                && ActionBehavior::isPristine($action, expectsLivewireClickHandler: $operation !== 'reorder');

            if ($isClient && ($operation === 'clone')) {
                $isClient = CloneSafety::isSafe($this);
            }

            $this->clientActions[$operation] = $isClient;

            if ((! $isClient) || ($operation === 'reorder')) {
                // Drag reorder never had a click handler; the Alpine
                // component overrides its `reorder()` instead.
                continue;
            }

            $action
                ->livewireClickHandlerEnabled(false)
                // The Alpine component handles these clicks by delegation:
                // a disabled button (move up on the first item) renders no
                // click directive at all, and the browser enables it later.
                ->extraAttributes([
                    'data-villament-repeater-action' => $operation,
                ], merge: true);
        }
    }

    /**
     * Renders the blank item under the placeholder key and returns it, the
     * pieces of chrome the browser needs to rebuild the list, and the item's
     * filled state, all inside one inert `<template>`. The placeholder never
     * survives this method: the raw state is restored before it returns, so
     * it is neither in the Livewire snapshot nor in the saved data.
     */
    protected function renderClientTemplate(): string
    {
        $token = ClientTemplateTokens::mainToken($this->getStatePath());
        $reference = $this->getTemplateReference();

        $originalState = $this->getRawState();
        $originalIsCollapsed = $this->isCollapsed;
        $invalidHtml = null;

        try {
            $this->isRenderingClientTemplate = true;

            // What stock's add action does: an empty item, then `fill()`,
            // which runs defaults and `afterStateHydrated()`.
            $this->rawState([$token => []]);
            $this->getChildSchema($token)->fill();

            if ($reference !== null) {
                // The ancestor's template carries ours: classify the item
                // (the shells and slots below need it), render nothing.
                $this->renderPlan = $this->planClientRendering($token);

                return $this->renderTemplateReference($reference);
            }

            $itemState = $this->getRawState()[$token] ?? [];

            // Stock renders a freshly added item expanded.
            $this->collapsed(false, shouldMakeComponentCollapsible: false);

            $needsSeparator = filled($this->getLabelBetweenItems())
                || $this->getAction($this->getAddBetweenActionName())?->isVisible();

            $secondToken = ClientTemplateTokens::secondToken($this->getStatePath());

            // Two rows when a separator has to be cut from between them, and
            // always for `table()`: its reorder column only renders from two.
            $this->rawState(($needsSeparator || $this->isTable()) ? [$token => $itemState, $secondToken => $itemState] : [$token => $itemState]);

            $html = parent::toEmbeddedHtml();

            $this->renderPlan = $this->planClientRendering($token);
            $invalidHtml = $this->renderInvalidVariants($token);

            $labelBetweenHtml = null;

            if ($needsSeparator && filled($this->getMaxItems())) {
                // At the limit stock drops "add between" (and shows
                // `labelBetweenItems()` instead, when set); the browser needs
                // both variants to follow the count.
                $this->isRenderingLabelBetweenTemplate = true;
                $labelBetweenHtml = HtmlFragments::elementAfter(parent::toEmbeddedHtml(), 'x-sortable-item="'.e($token).'"', 'li');
            }
        } finally {
            $this->isRenderingClientTemplate = false;
            $this->isRenderingLabelBetweenTemplate = false;
            $this->isCollapsed = $originalIsCollapsed;
            $this->rawState($originalState);
            $this->clearCachedChildSchemas();
        }

        $tokens = new ClientTemplateTokens($token, $this->getStatePath());

        [$html, $itemState] = $tokens->normalize($html, $itemState);

        if ($labelBetweenHtml !== null) {
            [$labelBetweenHtml] = $tokens->normalize($labelBetweenHtml, []);
        }

        if ($invalidHtml !== null) {
            [$invalidHtml] = $tokens->normalize($invalidHtml, []);
        }

        $item = HtmlFragments::element($html, 'x-sortable-item="'.e($token).'"', $this->isTable() ? 'tr' : 'li');

        if (($item !== null) && $this->isClientRendered()) {
            // A row the browser adds keeps its content across morphs too.
            if ($this->isTable()) {
                $start = $this->isReorderableWithButtons() || $this->isReorderableWithDragAndDrop() ? 1 : 0;
                $cells = array_slice($this->tableCells($item), $start, null);

                // The template row always has its actions cell when the
                // table has an actions column at all.
                if ($this->hasTableActionsColumn(ignoringMaxItems: true)) {
                    array_pop($cells);
                }

                $this->tableCells = ['start' => $start, 'count' => count($cells)];
                $item = self::decorateCells($item, $cells, $token);
            } else {
                $item = HtmlFragments::decorateTag($item, 0, $this->isSimple() ? 'class="fi-fo-simple-repeater-item-content"' : 'class="fi-fo-repeater-item-content"', ' wire:ignore');
            }
        }
        $separator = $needsSeparator ? HtmlFragments::elementAfter($html, 'x-sortable-item="'.e($token).'"', 'li') : null;
        $list = $this->isTable() ? $this->cutTableShell($html) : HtmlFragments::openingTag($html, 'x-ref="reorderItems"');
        $add = HtmlFragments::elementWithClass($html, match (true) {
            $this->isTable() => 'fi-fo-table-repeater-add',
            $this->isSimple() => 'fi-fo-simple-repeater-add',
            default => 'fi-fo-repeater-add',
        }, 'div');
        $description = HtmlFragments::element($html, 'data-reorder-description="'.e($token).'"', 'span');

        $meta = [
            'token' => $token,
            'tokens' => $tokens->all(),
            'state' => $itemState,
            'minItems' => $this->getMinItems(),
            'maxItems' => $this->getMaxItems(),
            'actions' => $this->clientActions,
            'hasItemNumbers' => $this->hasItemNumbers(),
            'layout' => match (true) {
                $this->isTable() => 'table',
                $this->isSimple() => 'simple',
                default => 'card',
            },
            // `table()` adds a leading reorder column (header + one cell per
            // row) only while there are two rows or more.
            'reorderColumn' => $this->isTable() && ($this->isReorderableWithButtons() || $this->isReorderableWithDragAndDrop()),
            'positionMessage' => __('filament-forms::components.repeater.reordering.position'),
            'rendering' => $this->isClientRendered() ? [
                'key' => $this->getKey(),
                'server' => $this->renderPlan['server'],
                // The template's provisional server units, hashed like a
                // row's slot hashes them.
                'unitHashes' => array_filter(array_combine(
                    $this->renderPlan['server'],
                    array_map(
                        fn (string $unit): ?string => (($unitHtml = HtmlFragments::elementOpenedBy((string) $item, 'wire:partial="schema-component::'.$this->getKey().'.'.$token.'.'.$unit.'"')) === null) ? null : self::unitHash($unitHtml, $token),
                        $this->renderPlan['server'],
                    ),
                )),
                'fields' => array_map(
                    fn (array $field): array => [
                        ...$field,
                        'invalid' => ($invalidHtml === null) ? null : HtmlFragments::collapseWhitespace((string) HtmlFragments::elementOpenedBy($invalidHtml, 'wire:partial="schema-component::'.$field['absoluteKey'].'"')),
                    ],
                    $this->renderPlan['fields'],
                ),
                'errorSentinel' => self::ERROR_SENTINEL,
                'cells' => $this->tableCells,
            ] : null,
        ];

        $content = '<script type="application/json" data-villament-repeater-meta>'.json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).'</script>'
            .($list !== null ? ($this->isTable() ? $list : $list.'</ul>') : '')
            // A `<tr>` outside a table is dropped by the HTML parser; in a
            // `<template>` of its own it is the first element, and parses.
            .($item === null ? '' : ($this->isTable() ? '<template data-villament-repeater-row>'.$item.'</template>' : $item))
            .($separator !== null ? '<template data-villament-repeater-separator="addable">'.$separator.'</template>' : '')
            .($labelBetweenHtml !== null ? '<template data-villament-repeater-separator="full">'.$labelBetweenHtml.'</template>' : '')
            .($add !== null ? '<template data-villament-repeater-add>'.$add.'</template>' : '')
            .($description ?? '');

        $content = HtmlFragments::collapseWhitespace($content);

        // Keyed by its content: when anything in it changes, Livewire's
        // morph replaces the whole element instead of patching attributes
        // around stale, inert content.
        return '<template '.self::TEMPLATE_ATTRIBUTE.' wire:key="villament-repeater-template.'.md5($content).'">'.$content.'</template>';
    }

    /**
     * When this repeater sits in a row of an ancestor `ClientRepeater`
     * whose template already carries this repeater's full template, the
     * reference that replaces our own copy: the ancestor's root id, our row
     * key in it, and our own meta (which may differ per instance).
     *
     * Inside the ancestor's template render, this repeater IS that copy:
     * it registers itself with the ancestor and renders in full.
     *
     * @return array{ancestor: string, item: string}|null
     */
    protected function getTemplateReference(): ?array
    {
        $ancestor = $this->getTemplateAncestor();

        if ($ancestor === null) {
            return null;
        }

        $prefix = $ancestor->getStatePath().'.';
        $statePath = $this->getStatePath();

        if (! str_starts_with($statePath, $prefix)) {
            return null;
        }

        [$item, $relative] = array_pad(explode('.', substr($statePath, strlen($prefix)), 2), 2, null);

        if (blank($item) || blank($relative)) {
            return null;
        }

        if ($ancestor->isRenderingClientTemplate) {
            $ancestor->templateSources[$relative] = true;

            return null;
        }

        // A closure on this repeater may evaluate differently per row, so
        // the ancestor's copy (rendered for a blank row) would not do.
        if (! isset($ancestor->templateSources[$relative]) || Renderability::hasAppClosures($this)) {
            return null;
        }

        return ['ancestor' => $ancestor->getId(), 'item' => $item];
    }

    /**
     * The closest `ClientRepeater` this one is nested in, if any.
     */
    protected function getTemplateAncestor(): ?self
    {
        $component = $this->getContainer()->getParentComponent();

        while ($component !== null) {
            if ($component instanceof self) {
                return $component;
            }

            $component = $component->getContainer()->getParentComponent();
        }

        return null;
    }

    /**
     * @param  array{ancestor: string, item: string}  $reference
     */
    protected function renderTemplateReference(array $reference): string
    {
        $json = json_encode([
            ...$reference,
            'meta' => [
                'minItems' => $this->getMinItems(),
                'maxItems' => $this->getMaxItems(),
                'actions' => $this->clientActions,
                'hasItemNumbers' => $this->hasItemNumbers(),
            ],
        ], JSON_THROW_ON_ERROR);

        return '<template '.self::TEMPLATE_ATTRIBUTE.' data-villament-repeater-template-ref="'.e($json).'" wire:key="villament-repeater-template-ref.'.md5($json).'"></template>';
    }

    /**
     * `table()`'s list shell: the whole `<table>` (its `<thead>` rendered
     * with the reorder column) around an empty `<tbody>`, for the browser to
     * put back when the first row is added. Stock renders no table at all
     * for an empty repeater.
     */
    protected function cutTableShell(string $html): ?string
    {
        $table = HtmlFragments::enclosing($html, 'x-ref="reorderItems"', 'table');
        $body = $table === null ? null : HtmlFragments::element($table, 'x-ref="reorderItems"', 'tbody');
        $opening = $table === null ? null : HtmlFragments::openingTag($table, 'x-ref="reorderItems"');

        if (($body === null) || ($opening === null)) {
            return null;
        }

        return str_replace($body, $opening.'</tbody>', $table);
    }

    /**
     * Classifies the template item (rendered under `$token`) into server
     * units and client fields, keyed relative to the item.
     *
     * @return array{server: list<string>, fields: list<array<string, mixed>>}|null
     */
    protected function planClientRendering(string $token): ?array
    {
        if (! $this->evaluate($this->hasClientRendering)) {
            return null;
        }

        $schema = $this->getChildSchema($token);
        $plan = $schema === null ? null : Renderability::plan($schema);

        if ($plan === null) {
            return null;
        }

        // `table()` renders a Hidden field bare inside the `<tr>`, without a
        // cell: markup the HTML parser moves out of the row, so there is no
        // cell for the browser to own. Such a table renders on the server.
        if ($this->isTable() && array_filter($plan['client'], fn (Field $field): bool => $field instanceof Hidden)) {
            return null;
        }

        $prefix = "{$this->getKey()}.{$token}.";
        $statePrefix = "{$this->getStatePath()}.{$token}.";
        $relative = fn (string $value, string $prefix): string => str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;

        return [
            'server' => array_values(array_map(
                fn (Component $component): string => $relative((string) $component->getKey(), $prefix),
                $plan['server'],
            )),
            'fields' => array_values(array_map(
                fn (Field $field): array => [
                    'key' => $relative((string) $field->getKey(), $prefix),
                    'absoluteKey' => (string) $field->getKey(),
                    'path' => $relative($field->getStatePath(), $statePrefix),
                    'nested' => $field instanceof HasNestedRecursiveValidationRules,
                    'html' => $field->areHtmlValidationMessagesAllowed(),
                ],
                array_filter($plan['client'], fn (Field $field): bool => filled($field->getKey()) && filled($field->getStatePath())),
            )),
        ];
    }

    /**
     * Renders the template item once more with a sentinel error on every
     * client field, so the browser has the exact invalid markup of each
     * field type (message element, `fi-invalid`, `aria-invalid`,
     * `aria-describedby`) without knowing any of it.
     */
    protected function renderInvalidVariants(string $token): ?string
    {
        if (($this->renderPlan === null) || ($this->renderPlan['fields'] === [])) {
            return null;
        }

        $bag = new MessageBag;

        foreach ($this->renderPlan['fields'] as $field) {
            $bag->add("{$this->getStatePath()}.{$token}.{$field['path']}", self::ERROR_SENTINEL);
        }

        $previous = view()->shared('errors');

        view()->share('errors', (new ViewErrorBag)->put('default', $bag));

        try {
            return parent::toEmbeddedHtml();
        } finally {
            view()->share('errors', $previous);
        }
    }

    /**
     * @param  array<Schema>  $items
     * @return array<string, mixed>
     */
    protected function getAlpineAttributes(array $items): array
    {
        $attributes = parent::getAlpineAttributes($items);

        if (! ($this->hasClientStructure() && array_filter($this->getClientActions()))) {
            return $attributes;
        }

        $baseSrc = FilamentAsset::getAlpineComponentSrc('repeater', 'filament/forms');
        $src = FilamentAsset::getAlpineComponentSrc('client-repeater', 'zvizvi/villament');

        $attributes['x-load-src'] = $src.(str_contains($src, '?') ? '&' : '?').'base='.rawurlencode($baseSrc);
        $attributes['x-data'] = 'clientRepeaterFormComponent('.substr((string) $attributes['x-data'], strlen('repeaterFormComponent('));

        return $attributes;
    }
}
