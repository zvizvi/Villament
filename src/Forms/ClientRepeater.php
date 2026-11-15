<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
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
use ReflectionFunction;
use WeakMap;
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

    /**
     * `null`: automatic — on when the whole item renders in the browser
     * (no server units), off otherwise. Measured, a mixed item costs more
     * server time than stock (DECISIONS.md), so that is an explicit opt-in.
     */
    protected bool|Closure|null $hasClientRendering = null;

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
     * `null`: automatic — on, unless the item carries an `afterStateHydrated()`
     * closure of the app's own. The template is filled on every render, so
     * such a hook would run on every request instead of only on an add
     * (stock); with side effects that is a behaviour change, so it needs an
     * explicit `clientStructure()`.
     */
    protected bool|Closure|null $hasClientStructure = null;

    protected ?bool $hasAppHydrationHooks = null;

    public function clientStructure(bool|Closure $condition = true): static
    {
        $this->hasClientStructure = $condition;

        return $this;
    }

    /**
     * Client rendering of the items' content. Automatic by default (see
     * `$hasClientRendering`); `clientRendering()` turns it on for an item
     * with server units too, `clientRendering(false)` off. Off, the server
     * renders every item; the structure stays in the browser.
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
        // `planClientRendering()` already returns null when it is off.
        return $this->renderPlan !== null;
    }

    /**
     * @return array<Schema>
     */
    public function getItems(): array
    {
        $items = parent::getItems();

        // The template's second row is only there for what renders between
        // or around two rows (a separator, `table()`'s reorder column): its
        // own content is never used, so it renders as an empty shell.
        if ($this->isRenderingClientTemplate && (count($items) === 2)) {
            $second = array_key_last($items);
            $items[$second] = $items[$second]->getClone()->components([]);

            return $items;
        }

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
        if (! app(LivewireManager::class)->isLivewireRequest()) {
            return true;
        }

        // The handshake: the browser's Alpine component announces itself on
        // every Livewire request, with the version of the module it runs.
        // Without it — the asset is not published, failed to load, or is a
        // stale copy — nothing would fill a shell, so the items render whole.
        if (self::$isClientFaked) {
            return false;
        }

        return request()->header(self::CLIENT_HEADER) !== self::clientAssetVersion();
    }

    protected static bool $isClientFaked = false;

    /**
     * Testing: behave as if the browser's Alpine component had made the
     * handshake on every Livewire request. Livewire's test requests rebuild
     * their headers (`X-Livewire` only), so a test cannot send it itself.
     */
    public static function fakeClient(bool $condition = true): void
    {
        self::$isClientFaked = $condition;
    }

    /**
     * The request header the Alpine component sets on every Livewire request.
     */
    public const CLIENT_HEADER = 'X-Villament-Client-Repeater';

    protected static ?string $clientAssetVersion = null;

    /**
     * The `?v=` the published Alpine component is served with: the module
     * reads it from its own URL and sends it back.
     */
    public static function clientAssetVersion(): string
    {
        if (self::$clientAssetVersion !== null) {
            return self::$clientAssetVersion;
        }

        parse_str((string) parse_url(FilamentAsset::getAlpineComponentSrc('client-repeater', 'zvizvi/villament'), PHP_URL_QUERY), $query);

        return self::$clientAssetVersion = (string) ($query['v'] ?? '');
    }

    /**
     * Whether this repeater's structure changes run in the browser at all.
     * False means the component behaves exactly like a stock `Repeater`.
     */
    public function hasClientStructure(): bool
    {
        $condition = $this->evaluate($this->hasClientStructure);

        if ($condition === null) {
            $condition = ! ($this->hasAppHydrationHooks ??= $this->memo('hydration', fn (): bool => (($schema = $this->getChildSchema()) !== null)
                && Renderability::schemaHasAppClosures($schema, onlyProperties: ['afterStateHydrated'], packagesAreVendor: true)));
        }

        if (! $condition) {
            return false;
        }

        // Integer keys: a JS object would re-sort them numerically.
        if ($this->generateUuidUsing === false) {
            return false;
        }

        // Stock's actions call `callAfterStateUpdated()` and, on a live
        // repeater, other fields react to the new state. The browser can do
        // neither, so these keep the server path for every action.
        if ($this->isLive() || filled($this->getOwnAfterStateUpdated())) {
            return false;
        }

        if ($this->isDisabled()) {
            return false;
        }

        return true;
    }

    /**
     * The `afterStateUpdated()` hooks set on THIS repeater: one every repeater
     * in the app carries (a `Field::configureUsing()` hook) is part of what
     * stock means here, not a reaction this one asked for. Matched by source,
     * against a freshly configured repeater, since closures are new objects on
     * every construction.
     *
     * @return array<Closure>
     */
    protected function getOwnAfterStateUpdated(): array
    {
        /** @var list<string> $global */
        $global = $this->memo('global-after-state-updated', fn (): array => array_map(
            self::sourceOf(...),
            static::make('villament-baseline')->afterStateUpdated,
        ));

        return array_values(array_filter(
            $this->afterStateUpdated,
            fn (Closure $hook): bool => ! in_array(self::sourceOf($hook), $global, strict: true),
        ));
    }

    private static function sourceOf(Closure $closure): string
    {
        $function = new ReflectionFunction($closure);

        return $function->getFileName().':'.$function->getStartLine();
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

        // One front-to-back pass: the rows come in document order, and the
        // output is built from slices, never by copying the whole document
        // once per row.
        $output = '';
        $cursor = 0;

        foreach ($keys as $key) {
            $row = HtmlFragments::boundsFrom($html, $cursor, 'x-sortable-item="'.e($key).'"', 'li');
            $contentAt = $row === null ? false : strpos($html, $contentClass, $row[0]);

            if (($row === null) || ($contentAt === false) || ($contentAt > $row[1])) {
                continue;
            }

            $tagStart = (int) strrpos($html, '<', $contentAt - strlen($html));

            if (! preg_match('/\G<[a-zA-Z][a-zA-Z0-9-]*/', $html, $tagName, offset: $tagStart)) {
                continue;
            }

            $slot = $this->renderRowSlot($key, renderedHtml: $isLight ? null : substr($html, $row[0], $row[1] - $row[0]));
            $nameEnd = $tagStart + strlen($tagName[0]);

            $output .= substr($html, $cursor, $tagStart - $cursor).$slot.$tagName[0].$attributes;
            $cursor = $nameEnd;
        }

        return $output.substr($html, $cursor);
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

        $output = '';
        $cursor = 0;

        foreach ($keys as $key) {
            $bounds = HtmlFragments::boundsFrom($html, $cursor, 'x-sortable-item="'.e($key).'"', 'tr');

            if ($bounds === null) {
                continue;
            }

            $row = substr($html, $bounds[0], $bounds[1] - $bounds[0]);

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

            $output .= substr($html, $cursor, $bounds[0] - $cursor).$decorated;
            $cursor = $bounds[1];
        }

        return $output.substr($html, $cursor);
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
                $isClient = $this->memo('clone', fn (): bool => CloneSafety::isSafe($this));
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
        $nestedItemKeys = [];

        try {
            $this->isRenderingClientTemplate = true;

            $this->rawState([$token => []]);

            if ($reference !== null) {
                // The ancestor's template carries ours: classify the item
                // (the shells and slots below need it), render nothing. The
                // classification is shared by every row's copy; only the
                // first fills an item for it.
                $this->renderPlan = $this->planClientRendering($token, fillFirst: true);

                return $this->renderTemplateReference($reference);
            }

            // What stock's add action does: an empty item, then `fill()`,
            // which runs defaults and `afterStateHydrated()`.
            $this->getChildSchema($token)->fill();

            $itemState = $this->getRawState()[$token] ?? [];
            $nestedItemKeys = $this->collectNestedItemKeys($this->getChildSchema($token));

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

        $tokens = new ClientTemplateTokens($token, $this->getStatePath(), $nestedItemKeys);

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
     * The item keys of every repeater and builder nested in `$schema`, at
     * any depth: the keys the template has to rename, and no others.
     *
     * @return list<string>
     */
    protected function collectNestedItemKeys(?Schema $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $keys = [];

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if (($component instanceof Repeater) || ($component instanceof Builder)) {
                $state = $component->getRawState();

                foreach (is_array($state) ? array_keys($state) : [] as $key) {
                    $keys[] = (string) $key;
                    $keys = [...$keys, ...$this->collectNestedItemKeys($component->getChildSchema($key))];
                }

                continue;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                $keys = [...$keys, ...$this->collectNestedItemKeys($childSchema)];
            }
        }

        return $keys;
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
     * The returned pair is the reference's address; `renderTemplateReference()`
     * adds the instance's own `meta` to it.
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

        // A closure on this repeater, or anywhere in its item — a field's
        // default read from the enclosing row, a label — may evaluate
        // differently per row, so the ancestor's copy (rendered for a blank
        // row) would not do.
        if (! isset($ancestor->templateSources[$relative]) || $this->memo('closures', fn (): bool => Renderability::hasAppClosuresDeep($this))) {
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
    protected function planClientRendering(string $token, bool $fillFirst = false): ?array
    {
        $condition = $this->evaluate($this->hasClientRendering);

        if ($condition === false) {
            return null;
        }

        // The classification depends on the configuration, not the row:
        // every row's copy of a nested repeater shares it (see `memo()`).
        $plan = $this->memo('plan', function () use ($token, $fillFirst): ?array {
            if ($fillFirst) {
                $this->getChildSchema($token)?->fill();
            }

            $schema = $this->getChildSchema($token);
            $plan = $schema === null ? null : Renderability::plan($schema);

            if ($plan === null) {
                return null;
            }

            $prefix = "{$this->getKey()}.{$token}.";
            $statePrefix = "{$this->getStatePath()}.{$token}.";
            $relative = fn (string $value, string $prefix): string => str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;

            return [
                'server' => array_map(
                    fn (Component $component): string => $relative((string) $component->getKey(), $prefix),
                    $plan['server'],
                ),
                'fields' => array_values(array_map(
                    fn (Field $field): array => [
                        'key' => $relative((string) $field->getKey(), $prefix),
                        'path' => $relative($field->getStatePath(), $statePrefix),
                        'nested' => $field instanceof HasNestedRecursiveValidationRules,
                        'html' => $field->areHtmlValidationMessagesAllowed(),
                    ],
                    array_filter($plan['client'], fn (Field $field): bool => filled($field->getKey()) && filled($field->getStatePath())),
                )),
                // `table()` renders a Hidden field bare inside the `<tr>`,
                // without a cell: markup the HTML parser moves out of the
                // row, so there is no cell for the browser to own.
                'hasHidden' => (bool) array_filter($plan['client'], fn (Field $field): bool => $field instanceof Hidden),
            ];
        });

        if ($plan === null) {
            return null;
        }

        // Automatic: only where it is a plain win, an item with no server
        // units. A mixed item renders its units per row on the server and
        // still costs more server time than stock (DECISIONS.md).
        if (($condition === null) && ($plan['server'] !== [])) {
            return null;
        }

        if ($this->isTable() && $plan['hasHidden']) {
            return null;
        }

        return [
            'server' => $plan['server'],
            'fields' => array_map(
                fn (array $field): array => [...$field, 'absoluteKey' => "{$this->getKey()}.{$token}.{$field['key']}"],
                $plan['fields'],
            ),
        ];
    }

    /**
     * Per-render memo, shared by every instance of the same repeater
     * definition: a nested repeater is one object per outer row, all with
     * the same configuration, and classifying it (reflection over its whole
     * schema, a `fill()`) costs the same for each. Keyed by the Livewire
     * component (so it lives one request, Octane included) and by the state
     * path with every item key replaced by `*`.
     *
     * Only results that depend on the configuration — which closures exist,
     * which classes — go through here, never anything a row's state decides.
     */
    protected function memo(string $what, Closure $compute): mixed
    {
        $livewire = $this->getLivewire();

        self::$memos ??= new WeakMap;

        $key = $what.'|'.static::class.'|'.preg_replace(
            '/(?<=^|\.)(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|villament-[0-9a-f]{16}-\d{4}|record-[^.]+|\d+)(?=\.|$)/',
            '*',
            (string) $this->getStatePath(),
        );

        $bucket = self::$memos[$livewire] ?? [];

        if (! array_key_exists($key, $bucket)) {
            $bucket[$key] = $compute();
            self::$memos[$livewire] = $bucket;
        }

        return $bucket[$key];
    }

    /**
     * @var WeakMap<object, array<string, mixed>>|null
     */
    protected static ?WeakMap $memos = null;

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

        // Each field alone, through the same `toSchemaHtml()` the item's
        // render uses for it: the variant is exactly that unit, and nothing
        // else of the item (a nested repeater, a file upload) renders again.
        $schema = $this->getChildSchema($token);
        $html = '';

        try {
            foreach ($this->renderPlan['fields'] as $field) {
                $html .= ($schema === null ? null : Renderability::findByKey($schema, $field['absoluteKey']))?->toSchemaHtml() ?? '';
            }
        } finally {
            view()->share('errors', $previous);
        }

        return $html;
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
