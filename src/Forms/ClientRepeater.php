<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentAsset;
use Zvizvi\Villament\Forms\Support\ActionBehavior;
use Zvizvi\Villament\Forms\Support\CloneSafety;
use Zvizvi\Villament\Forms\Support\HtmlFragments;

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
 * Anything the browser cannot reproduce exactly keeps the stock Livewire
 * path: an action the developer customised with behaviour (confirmation,
 * modal, form, lifecycle hooks, a replaced `action()`), `extraItemActions()`,
 * a `live()` repeater or one with `afterStateUpdated()`, the `simple()` and
 * `table()` layouts, `generateUuidUsing(false)`, and cloning an item whose
 * markup depends on its state (see `CloneSafety`). See DECISIONS.md.
 */
class ClientRepeater extends Repeater
{
    public const TEMPLATE_ATTRIBUTE = 'data-villament-repeater-template';

    protected bool $isRenderingClientTemplate = false;

    protected bool $isRenderingLabelBetweenTemplate = false;

    protected bool $hasPreparedClientActions = false;

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
     * Whether this repeater's structure changes run in the browser at all.
     * False means the component behaves exactly like a stock `Repeater`.
     */
    public function hasClientStructure(): bool
    {
        if (! $this->evaluate($this->hasClientStructure)) {
            return false;
        }

        // The default (card) and `simple()` layouts are client-side; the
        // `table()` layout renders rows, not items, and stays stock.
        if ($this->isTable()) {
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

        $html = parent::toEmbeddedHtml();

        if (! array_filter($this->clientActions)) {
            return $html;
        }

        return HtmlFragments::insertAfterOpeningTag(
            $html,
            'data-reorder-owner',
            $this->renderClientTemplate(),
        );
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

        $originalState = $this->getRawState();
        $originalIsCollapsed = $this->isCollapsed;

        try {
            $this->isRenderingClientTemplate = true;

            // What stock's add action does: an empty item, then `fill()`,
            // which runs defaults and `afterStateHydrated()`.
            $this->rawState([$token => []]);
            $this->getChildSchema($token)->fill();

            $itemState = $this->getRawState()[$token] ?? [];

            // Stock renders a freshly added item expanded.
            $this->collapsed(false, shouldMakeComponentCollapsible: false);

            $needsSeparator = filled($this->getLabelBetweenItems())
                || $this->getAction($this->getAddBetweenActionName())?->isVisible();

            $secondToken = ClientTemplateTokens::secondToken($this->getStatePath());

            $this->rawState($needsSeparator ? [$token => $itemState, $secondToken => $itemState] : [$token => $itemState]);

            $html = parent::toEmbeddedHtml();

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

        $item = HtmlFragments::element($html, 'x-sortable-item="'.e($token).'"', 'li');
        $separator = $needsSeparator ? HtmlFragments::elementAfter($html, 'x-sortable-item="'.e($token).'"', 'li') : null;
        $list = HtmlFragments::openingTag($html, 'x-ref="reorderItems"');
        $add = HtmlFragments::elementWithClass($html, $this->isSimple() ? 'fi-fo-simple-repeater-add' : 'fi-fo-repeater-add', 'div');
        $description = HtmlFragments::element($html, 'data-reorder-description="'.e($token).'"', 'span');

        $meta = [
            'token' => $token,
            'tokens' => $tokens->all(),
            'state' => $itemState,
            'minItems' => $this->getMinItems(),
            'maxItems' => $this->getMaxItems(),
            'actions' => $this->clientActions,
            'hasItemNumbers' => $this->hasItemNumbers(),
            'positionMessage' => __('filament-forms::components.repeater.reordering.position'),
        ];

        $content = '<script type="application/json" data-villament-repeater-meta>'.json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).'</script>'
            .($list !== null ? $list.'</ul>' : '')
            .($item ?? '')
            .($separator !== null ? '<template data-villament-repeater-separator="addable">'.$separator.'</template>' : '')
            .($labelBetweenHtml !== null ? '<template data-villament-repeater-separator="full">'.$labelBetweenHtml.'</template>' : '')
            .($add !== null ? '<template data-villament-repeater-add>'.$add.'</template>' : '')
            .($description ?? '');

        // Keyed by its content: when anything in it changes, Livewire's
        // morph replaces the whole element instead of patching attributes
        // around stale, inert content.
        return '<template '.self::TEMPLATE_ATTRIBUTE.' wire:key="villament-repeater-template.'.md5($content).'">'.$content.'</template>';
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
