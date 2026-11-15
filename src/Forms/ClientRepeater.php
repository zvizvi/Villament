<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Contracts\HasNestedRecursiveValidationRules;
use Filament\Forms\Components\Field;
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

        $contentClass = $this->isSimple() ? 'class="fi-fo-simple-repeater-item-content"' : 'class="fi-fo-repeater-item-content"';
        $attributes = ' wire:ignore'.($isLight ? ' data-villament-repeater-pending' : '');

        foreach ($keys as $key) {
            $itemStart = strpos($html, 'x-sortable-item="'.e($key).'"');

            if ($itemStart === false) {
                continue;
            }

            $html = HtmlFragments::decorateTag($html, $itemStart, $contentClass, $attributes, before: $this->renderRowSlot($key));
        }

        return $html;
    }

    /**
     * The row's ONE slot: every server unit of the item, rendered for this
     * row through Filament's own `toSchemaHtml()` (the component with its
     * `wire:partial` wrapper — exactly what the item's own render emits).
     * Keyed by its content, so the morph replaces it when it changes.
     */
    protected function renderRowSlot(string $key): string
    {
        $units = $this->renderPlan['server'] ?? [];
        $schema = $units === [] ? null : $this->getChildSchema($key);

        if ($schema === null) {
            return '';
        }

        $html = '';

        foreach ($units as $unit) {
            $html .= Renderability::findByKey($schema, "{$this->getKey()}.{$key}.{$unit}")?->toSchemaHtml() ?? '';
        }

        if ($html === '') {
            return '';
        }

        // The browser owns what it inserts, like the template's content.
        $html = HtmlFragments::collapseWhitespace($html);
        $hash = md5(str_replace($key, '{key}', $html));

        return '<template data-villament-repeater-slot wire:key="villament-repeater-slot.'.e($key).'.'.$hash.'">'.$html.'</template>';
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
        $invalidHtml = null;

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

        $item = HtmlFragments::element($html, 'x-sortable-item="'.e($token).'"', 'li');

        if (($item !== null) && $this->isClientRendered()) {
            // A row the browser adds keeps its content across morphs too.
            $item = HtmlFragments::decorateTag($item, 0, $this->isSimple() ? 'class="fi-fo-simple-repeater-item-content"' : 'class="fi-fo-repeater-item-content"', ' wire:ignore');
        }
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
            'rendering' => $this->isClientRendered() ? [
                'key' => $this->getKey(),
                'server' => $this->renderPlan['server'],
                'fields' => array_map(
                    fn (array $field): array => [
                        ...$field,
                        'invalid' => ($invalidHtml === null) ? null : HtmlFragments::collapseWhitespace((string) HtmlFragments::elementOpenedBy($invalidHtml, 'wire:partial="schema-component::'.$field['absoluteKey'].'"')),
                    ],
                    $this->renderPlan['fields'],
                ),
                'errorSentinel' => self::ERROR_SENTINEL,
            ] : null,
        ];

        $content = '<script type="application/json" data-villament-repeater-meta>'.json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).'</script>'
            .($list !== null ? $list.'</ul>' : '')
            .($item ?? '')
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
