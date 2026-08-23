<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Concerns;

use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Js;
use ReflectionFunction;
use Throwable;

/**
 * Moves a repeater's ITEM OPERATIONS — add, clone, delete, move up/down and
 * drag reorder — from Livewire round trips into the browser, WITHOUT
 * rendering a single field on the client.
 *
 * Stock runs every one of them as an `Action` whose callback mutates the raw
 * state array and then calls `partiallyRender()` (`Repeater::getAddAction()`
 * and siblings). That is a request plus a re-render of EVERY item to move one
 * row — and drag reorder pays it even though the browser has already moved
 * the DOM (the `x-on:end.stop` stock binds on the items list).
 *
 * All five are pure manipulations of the state array. None of them needs a
 * field evaluated, so none of them needs the server:
 *
 * - Existing rows already have server-rendered DOM. Delete removes a node,
 *   reorder moves nodes, and both write the new key order back through
 *   `$wire.$set(path, …, false)` — no request.
 * - A NEW row is cloned from a blueprint: one extra item, rendered through
 *   Filament's own renderer under the state path `BLUEPRINT_ITEM_KEY` and
 *   parked inert inside a `<template>`. Because a component's key defaults to
 *   its state path (`Filament\Schemas\Concerns\HasKey::getKey()`) and every
 *   item is built as `getChildSchema()->statePath($itemKey)`
 *   (`Repeater::getDefaultChildSchemas()`), that key is embedded in every
 *   descendant's `id`, `wire:key`, `wire:model` and `x-data`. Replacing the
 *   token with a fresh UUID therefore reproduces byte-for-byte what the
 *   server would have rendered for that UUID, so Livewire's next morph agrees
 *   with the DOM instead of fighting it.
 *
 * Nothing changes on the server: `getDefaultChildSchemas()` rebuilds items
 * from the raw state, so validation, dehydration and `relationship()` saving
 * never learn that a row was born in the browser.
 *
 * No markup is copied from `Repeater::toEmbeddedHtml()`. The Alpine scope
 * arrives through `extraAttributes()`, the buttons through
 * `Action::alpineClickHandler()` — which disables the Livewire click handler
 * for us — and the blueprint through Filament's own renderer on a clone.
 *
 * The trait lives apart from `VillamentRepeater` so an app that already
 * subclasses `Repeater` can adopt the behaviour without changing its parent.
 */
trait HasClientSideItems
{
    /**
     * Item key the blueprint is rendered under, and the token the browser
     * swaps for a fresh UUID. Never present in real state.
     */
    public const BLUEPRINT_ITEM_KEY = '__villamentBlueprintItem';

    protected bool|Closure|null $hasClientSideItems = null;

    /**
     * Mirrors `afterStateUpdated()`, whose callback list the parent keeps in a
     * protected property. Client-side operations never call
     * `callAfterStateUpdated()`, so a repeater carrying a hook stays on stock.
     */
    protected bool $hasAfterStateUpdatedHooks = false;

    /** True on the clone that renders the blueprint, to stop it recursing. */
    protected bool $isClientSideBlueprint = false;

    /**
     * Set on the blueprint clone so its raw state comes from here instead of
     * the Livewire component — the clone keeps the REAL state path, so every
     * key and id it renders is the real one with the token in place of a UUID.
     *
     * @var array<string, mixed> | null
     */
    protected ?array $clientSideBlueprintRawState = null;

    /** @var array<string, mixed> | null */
    protected ?array $cachedClientSideItemDefaults = null;

    /**
     * The gate is asked once per action plus twice more per render, and one of
     * its clauses builds an `Action`. Its inputs are all configuration rather
     * than state, so one answer per component is correct.
     */
    protected ?bool $cachedHasClientSideItems = null;

    public function clientSideItems(bool|Closure|null $condition = true): static
    {
        $this->hasClientSideItems = $condition;

        return $this;
    }

    public function afterStateUpdated(?Closure $callback): static
    {
        // `null` resets the parent's callback list; mirror that exactly.
        $this->hasAfterStateUpdatedHooks = $callback instanceof Closure;
        $this->cachedHasClientSideItems = null;

        return parent::afterStateUpdated($callback);
    }

    /**
     * Whether item operations may run in the browser.
     *
     * Every `false` below is a case where stock evaluates PHP the client
     * cannot reproduce, or renders something position-dependent that goes
     * stale the moment a row moves. Degrading is always CORRECT — it costs
     * the round trip and nothing else — so the gate errs towards it.
     */
    public function hasClientSideItems(): bool
    {
        return $this->cachedHasClientSideItems ??= $this->resolveHasClientSideItems();
    }

    protected function resolveHasClientSideItems(): bool
    {
        if (($explicit = $this->evaluate($this->hasClientSideItems)) !== null) {
            return (bool) $explicit;
        }

        return ! (
            // A live repeater round trips on every change by definition, and
            // its `afterStateUpdated` runs server-side.
            $this->isLive() ||
            $this->hasAfterStateUpdatedHooks ||
            // Rendered positions: stock re-renders to renumber, we do not.
            $this->hasItemNumbers() ||
            $this->hasIndexDependentItemLabel() ||
            // Both render extra nodes BETWEEN items, which a cloned row has
            // no way to bring with it.
            $this->getAddBetweenAction()->isVisible() ||
            filled($this->getLabelBetweenItems()) ||
            // `table()` builds its columns from the item COUNT
            // (`$hasReorderColumn = ($itemCount > 1) && …`), so a blueprint
            // rendered alone would carry the wrong number of cells.
            $this->isTable()
        );
    }

    /**
     * `itemLabel(fn (int $index) => …)` numbers rows by position, so it goes
     * stale on delete and reorder. Filament injects closure arguments by
     * parameter NAME, so the parameter name is the honest signal.
     */
    protected function hasIndexDependentItemLabel(): bool
    {
        if (! ($this->itemLabel instanceof Closure)) {
            return false;
        }

        foreach ((new ReflectionFunction($this->itemLabel))->getParameters() as $parameter) {
            if ($parameter->getName() === 'index') {
                return true;
            }
        }

        return false;
    }

    protected function shouldRenderClientSideItems(): bool
    {
        return $this->hasClientSideItems() && (! $this->isClientSideBlueprint);
    }

    /**
     * The Alpine scope and the state the browser needs, merged through
     * `extraAttributes()` so stock's own renderer places them.
     *
     * `x-on:end.capture` is how the drag round trip is cancelled without
     * touching stock's markup: stock binds `x-on:end.stop` on the items list,
     * and a CAPTURE listener on this ancestor runs first, so stopping
     * propagation there keeps the event from ever reaching it. The handler
     * stops the event itself rather than declaring `.stop` here, so a NESTED
     * repeater's drag — which passes through this listener on its way down —
     * still reaches its own.
     *
     * @return array<string, mixed>
     */
    public function getExtraAttributes(): array
    {
        $attributes = parent::getExtraAttributes();

        if (! $this->shouldRenderClientSideItems()) {
            return $attributes;
        }

        return [
            ...$attributes,
            'x-data' => 'villamentRepeater('.Js::from([
                'statePath' => $this->getStatePath(),
                'blueprintKey' => $this->getKey(),
                'itemKey' => static::BLUEPRINT_ITEM_KEY,
                'itemDefaults' => $this->getClientSideItemDefaults(),
                'maxItems' => $this->getMaxItems(),
            ])->toHtml().')',
            'x-on:end.capture' => 'reorderFromSortable($event)',
        ];
    }

    public function getRawState(): mixed
    {
        return $this->clientSideBlueprintRawState ?? parent::getRawState();
    }

    public function toEmbeddedHtml(): string
    {
        if (! $this->shouldRenderClientSideItems()) {
            return parent::toEmbeddedHtml();
        }

        // Before the real render, so the scratch item `fill()` writes and the
        // cleanup that follows it are both finished by the time stock reads
        // this component's raw state.
        $blueprint = $this->renderClientSideBlueprint();

        return parent::toEmbeddedHtml().$blueprint;
    }

    /**
     * Renders ONE stock item — chrome and all, in whatever variant this
     * repeater uses — through Filament's own renderer, so nothing here has to
     * know what a repeater item looks like.
     */
    protected function renderClientSideBlueprint(): string
    {
        $html = $this->withClientSideBlueprint(
            fn (self $blueprint): string => $blueprint->toEmbeddedHtml(),
        );

        return '<template wire:ignore data-villament-repeater-blueprint="'.e((string) $this->getKey()).'">'
            .$html
            .'</template>';
    }

    /**
     * Builds the blueprint clone, hands it to `$callback`, and always removes
     * the scratch item it seeded into the Livewire state.
     *
     * The clone KEEPS the real state path. Only its raw state is overridden
     * (see `getRawState()`), so it renders the real keys, ids and state paths
     * with `BLUEPRINT_ITEM_KEY` standing in for a UUID — one token for the
     * browser to replace, rather than a scratch prefix to unpick.
     *
     * `fill()` still writes the item's defaults through to the Livewire
     * component, since that is how Filament applies defaults; the sentinel key
     * it lands under is forgotten again before anything else reads the state.
     *
     * @template T
     *
     * @param  Closure(self): T  $callback
     * @return T
     */
    protected function withClientSideBlueprint(Closure $callback): mixed
    {
        $livewire = $this->getLivewire();
        $scratchPath = $this->getStatePath().'.'.static::BLUEPRINT_ITEM_KEY;

        $blueprint = $this->getClone();
        $blueprint->isClientSideBlueprint = true;
        $blueprint->clientSideBlueprintRawState = [static::BLUEPRINT_ITEM_KEY => []];
        $blueprint->clearCachedChildSchemas();
        // Stock's add action expands the row it creates; match it, without
        // making a non-collapsible repeater collapsible.
        $blueprint->collapsed(false, shouldMakeComponentCollapsible: false);

        try {
            $blueprint->getChildSchema(static::BLUEPRINT_ITEM_KEY)?->fill();

            $this->cachedClientSideItemDefaults ??= (array) (data_get($livewire, $scratchPath) ?? []);

            return $callback($blueprint);
        } finally {
            data_forget($livewire, $scratchPath);
        }
    }

    /**
     * The raw state `fill()` produces for a fresh item — what the browser
     * writes into `$wire` when it adds a row, exactly as stock's add action
     * does with `getChildSchema($newUuid)->fill()`.
     *
     * @return array<string, mixed>
     */
    protected function getClientSideItemDefaults(): array
    {
        if ($this->cachedClientSideItemDefaults !== null) {
            return $this->cachedClientSideItemDefaults;
        }

        $this->withClientSideBlueprint(fn (): null => null);

        return $this->cachedClientSideItemDefaults ??= [];
    }

    /**
     * Swaps an action's Livewire click handler for an Alpine one.
     *
     * `alpineClickHandler()` disables the Livewire handler itself
     * (`Filament\Actions\Action::alpineClickHandler()`), so the button stays
     * stock in every other respect — classes, icon, tooltip, disabled state.
     */
    protected function clientSideItemsAction(Action $action, string $handler): Action
    {
        if (! $this->hasClientSideItems()) {
            return $action;
        }

        // A confirmation or form modal is server-owned: mounting it IS the
        // round trip, and bypassing it would drop the developer's guard.
        if ($this->opensModal($action)) {
            return $action;
        }

        return $action->alpineClickHandler($handler);
    }

    protected function opensModal(Action $action): bool
    {
        try {
            return $action->shouldOpenModal(
                checkForSchemaUsing: fn (Action $action): bool => filled(
                    $action->getSchema($this->getContainer()),
                ),
            );
        } catch (Throwable) {
            // Unknown shape: assume server-owned, which is the safe answer.
            return true;
        }
    }

    public function getAddAction(): Action
    {
        return $this->clientSideItemsAction(parent::getAddAction(), 'addItem()');
    }

    public function getDeleteAction(): Action
    {
        return $this->clientSideItemsAction(
            parent::getDeleteAction(),
            'deleteItem($el)',
        );
    }

    public function getCloneAction(): Action
    {
        return $this->clientSideItemsAction(
            parent::getCloneAction(),
            'cloneItem($el)',
        );
    }

    public function getMoveUpAction(): Action
    {
        return $this->clientSideMoveAction(parent::getMoveUpAction(), 'up', 'moveItem($el, -1)');
    }

    public function getMoveDownAction(): Action
    {
        return $this->clientSideMoveAction(parent::getMoveDownAction(), 'down', 'moveItem($el, 1)');
    }

    /**
     * Stock disables move-up on the first row and move-down on the last, and
     * recomputes that during the re-render we are removing. The marker lets
     * the browser find the two buttons and maintain the state itself, rather
     * than matching on a click handler's text.
     *
     * A TOOLTIPPED button is left on stock: `<x-filament::icon-button>` strips
     * every `x-on:` attribute from a button that is both disabled and
     * tooltipped, so the first and last rows would render with no handler at
     * all and could never be re-enabled from here.
     */
    protected function clientSideMoveAction(Action $action, string $direction, string $handler): Action
    {
        if (! $this->hasClientSideItems()) {
            return $action;
        }

        if (filled($action->getTooltip())) {
            return $action;
        }

        return $this->clientSideItemsAction(
            $action->extraAttributes(['data-villament-move' => $direction], merge: true),
            $handler,
        );
    }
}
