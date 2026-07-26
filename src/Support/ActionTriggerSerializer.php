<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Size;
use Filament\Support\Facades\FilamentView;
use Filament\Support\View\ComponentAttributeBag;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Support\View\Components\DropdownComponent\ItemComponent;
use Filament\Support\View\Components\DropdownComponent\ItemComponent\IconComponent;
use Filament\Support\View\Components\IconButtonComponent;
use Filament\Support\View\Components\LinkComponent;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\generate_icon_html;

/**
 * Turns one Filament `Action` into the payload the island's `ActionTrigger`
 * renders — for ANY trigger view (`link()`, `button()`, `iconButton()`,
 * `badge()`, or a dropdown item).
 *
 * The point of this class is that **no class list is reinvented in JS**. Each
 * view's class/style pair is built here through the very same
 * `->class([...])->color(Component::class, $color)` chain Filament's own
 * `generate*Html()` methods use, so a palette override, a custom color array
 * or a Filament upgrade all flow through untouched. Vue receives finished
 * strings and an element name.
 *
 * Deliberately NOT carried (they have no meaning inside the island, which has
 * no Alpine and no Livewire bindings on its own markup):
 * `keyBindings()` (`x-mousetrap`), `labeledFrom()` (stock renders a SECOND,
 * breakpoint-swapped element), `postToUrl()` (a `<form>` tag) and the
 * `wire:loading` indicator swap (tracked in BACKLOG.md).
 */
final class ActionTriggerSerializer
{
    /**
     * A group's children with their dropdown-item view already stamped.
     *
     * `ActionGroup::getActions()` is what applies
     * `defaultView(GROUPED_VIEW)` — stock's own dropdown blade calls it for
     * exactly that reason — while `getFlatActions()` returns the same
     * instances WITHOUT the stamping. Reaching for the flat list alone would
     * serialize a grouped action with its standalone view.
     *
     * @return array<string, Action>
     */
    public function groupChildren(ActionGroup $group): array
    {
        $group->getActions();

        return $group->getFlatActions();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Action $action): array
    {
        // `getView()` is already authoritative for grouping: `ActionGroup`
        // stamps `defaultView(GROUPED_VIEW)` (or `BUTTON_VIEW` for a button
        // group) on every child as it is pushed.
        $view = $this->view($action);
        $url = $action->getUrl();
        $isDisabled = $action->isDisabled();
        $newTab = filled($url) && $action->shouldOpenUrlInNewTab();

        // Stock only keeps the anchor when the action is actually navigable.
        $isAnchor = filled($url) && ! $isDisabled;
        $tag = $isAnchor ? 'a' : 'button';

        $badge = $action->getBadge();
        $label = $action->getLabel();
        $tooltip = $action->getTooltip();

        return [
            'name' => $action->getName(),
            'view' => $view,
            'tag' => $tag,
            'label' => $this->text($label),
            // `hiddenLabel()`: the label survives only as the accessible name.
            'labelHidden' => $view !== 'grouped' && $action->isLabelHidden(),
            'iconHtml' => $this->iconHtml($action, $view),
            'iconPosition' => $action->getIconPosition() === IconPosition::After ? 'after' : 'before',
            'badge' => $this->text($badge),
            ...$this->badgeAttributes($action, $badge),
            'tooltip' => $this->text($tooltip),
            'disabled' => $isDisabled,
            'url' => $isAnchor ? $url : null,
            'newTab' => $isAnchor && $newTab,
            'wireNavigate' => $isAnchor && ! $newTab && FilamentView::hasSpaMode($url),
            'extraAttributes' => $action->getExtraAttributes(),
            ...$this->attributes($action, $view, $isDisabled),
        ];
    }

    /**
     * Which of Filament's trigger views this action renders as. Anything
     * custom falls back to `link` — the island cannot render an arbitrary
     * Blade view, and a link is the table default.
     */
    private function view(Action $action): string
    {
        return match ($action->getView()) {
            Action::BADGE_VIEW => 'badge',
            Action::BUTTON_VIEW => 'button',
            Action::GROUPED_VIEW => 'grouped',
            Action::ICON_BUTTON_VIEW => 'iconButton',
            default => 'link',
        };
    }

    /**
     * The element's finished `class` (and `style`, for array colors) — built
     * with the same bag chain as the matching `generate*Html()` method, in the
     * same order, so the output string is identical.
     *
     * @return array{class: string, style: ?string}
     */
    private function attributes(Action $action, string $view, bool $isDisabled): array
    {
        $color = $action->getColor();

        // Stock's order is: the `fi-ac-*-action` marker (already on the bag the
        // `to*Html()` method hands over), then the component's own classes,
        // then the color classes. Same order here.
        $bag = match ($view) {
            'button' => (new ComponentAttributeBag)
                ->class(['fi-ac-btn-action'])
                ->class([
                    'fi-btn',
                    'fi-disabled' => $isDisabled,
                    'fi-outlined' => $action->isOutlined(),
                    'fi-size-'.$this->size($action, default: 'md'),
                ])
                ->color(ButtonComponent::make($action->isOutlined()), $color ?? 'primary'),
            'iconButton' => (new ComponentAttributeBag)
                ->class(['fi-ac-icon-btn-action'])
                ->class([
                    'fi-icon-btn',
                    'fi-disabled' => $isDisabled,
                    'fi-size-'.$this->size($action, default: 'md'),
                ])
                ->color(IconButtonComponent::class, $color ?? 'primary'),
            // The badge trigger is the one view with NO size default — an
            // unsized badge carries no `fi-size-*` class at all.
            'badge' => (new ComponentAttributeBag)
                ->class(['fi-ac-badge-action'])
                ->class(array_filter([
                    'fi-badge',
                    'fi-disabled' => $isDisabled,
                    ($size = $this->size($action)) === null ? null : "fi-size-{$size}",
                ]))
                ->color(BadgeComponent::class, $color ?? 'primary'),
            // Dropdown items default to GRAY, not primary — that is why an
            // uncolored grouped action carries no color classes at all.
            'grouped' => (new ComponentAttributeBag)
                ->class(['fi-ac-grouped-action'])
                ->class([
                    'fi-dropdown-list-item',
                    'fi-disabled' => $isDisabled,
                ])
                ->color(ItemComponent::class, $color ?? 'gray'),
            default => (new ComponentAttributeBag)
                ->class(['fi-ac-link-action'])
                ->class([
                    'fi-link',
                    'fi-disabled' => $isDisabled,
                    'fi-size-'.$this->size($action, default: 'md'),
                ])
                ->color(LinkComponent::class, $color ?? 'primary'),
        };

        return [
            'class' => (string) ($bag->get('class') ?? ''),
            'style' => blank($style = $bag->get('style')) ? null : (string) $style,
        ];
    }

    /**
     * The icon, at the size the matching component would have picked. Table
     * actions carry their icon on `tableIcon()` / `groupedIcon()` rather than
     * `icon()` (v5's ViewAction/EditAction do exactly that), which is why the
     * defaults differ per view.
     */
    private function iconHtml(Action $action, string $view): ?string
    {
        $icon = $view === 'grouped'
            ? $action->getIcon(default: $action->getGroupedIcon())
            : $action->getIcon(default: $action->getTable() !== null ? $action->getTableIcon() : null);

        if (blank($icon)) {
            return null;
        }

        $size = $action->getIconSize() ?? $this->defaultIconSize($view, $this->size($action, default: 'md'));

        $attributes = new ComponentAttributeBag;

        // Stock tints a dropdown item's icon with the item color.
        if ($view === 'grouped') {
            $attributes = $attributes->color(IconComponent::class, $action->getColor() ?? 'gray');
        }

        return generate_icon_html($icon, attributes: $attributes, size: $size)?->toHtml();
    }

    private function defaultIconSize(string $view, ?string $size): ?IconSize
    {
        if ($view === 'badge') {
            return IconSize::Small;
        }

        if ($view === 'iconButton') {
            return match ($size) {
                'xs' => IconSize::Small,
                'lg', 'xl' => IconSize::Large,
                default => null,
            };
        }

        return match ($size) {
            'xs', 'sm' => IconSize::Small,
            default => null,
        };
    }

    /**
     * The counter badge hung off a link/button/icon-button trigger. Its own
     * color bag is independent of the action's.
     *
     * @return array{badgeClass: ?string, badgeStyle: ?string}
     */
    private function badgeAttributes(Action $action, mixed $badge): array
    {
        if (blank($badge)) {
            return ['badgeClass' => null, 'badgeStyle' => null];
        }

        $bag = (new ComponentAttributeBag)
            ->class(['fi-badge', 'fi-size-xs'])
            ->color(BadgeComponent::class, $action->getBadgeColor($this->text($badge)) ?? 'primary');

        return [
            'badgeClass' => (string) ($bag->get('class') ?? ''),
            'badgeStyle' => blank($style = $bag->get('style')) ? null : (string) $style,
        ];
    }

    /**
     * The action's size as Filament's `fi-size-*` suffix, or null for the
     * component default (which emits no size class).
     */
    private function size(Action $action, ?string $default = null): ?string
    {
        $size = $action->getSize();

        if ($size instanceof Size) {
            return $size->value;
        }

        return is_string($size) ? $size : $default;
    }

    private function text(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof Htmlable ? strip_tags($value->toHtml()) : (string) $value;
    }
}
