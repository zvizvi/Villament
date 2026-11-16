<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;

/**
 * Tells a structural repeater action that still does exactly what stock
 * defines from one a developer changed the BEHAVIOUR of.
 *
 * Presentation (label, icon, color, size, tooltip, `visible()`, extra
 * attributes) is free to change: the browser replays the same markup. What
 * sends an action back to the Livewire path is anything that has to run on
 * the server or open UI the browser cannot: a replaced `action()`, lifecycle
 * hooks, a confirmation, a modal or form, a URL, a dispatched event, a custom
 * Alpine handler — and `disabled()`, whose state the browser would overwrite
 * when it recomputes the first / last move buttons.
 */
final class ActionBehavior
{
    /**
     * Server-side hooks an action runs around its `action()` callback.
     */
    private const HOOK_PROPERTIES = [
        'before',
        'after',
        'beforeFormFilled',
        'afterFormFilled',
        'beforeFormValidated',
        'afterFormValidated',
        'schema',
    ];

    public static function isPristine(Action $action, bool $expectsLivewireClickHandler = true): bool
    {
        if (! self::hasStockActionFunction($action)) {
            return false;
        }

        foreach (self::HOOK_PROPERTIES as $property) {
            $value = self::readProperty($action, $property);

            if (($value !== null) && (! self::isGloballyConfigured($property, $value))) {
                return false;
            }
        }

        if ($action->isLivewireClickHandlerEnabled() !== $expectsLivewireClickHandler) {
            return false;
        }

        // `disabled()` is not presentation here: the browser recomputes a
        // structural button's disabled state (first / last for the moves)
        // and would overwrite a condition the app set.
        if (self::readProperty($action, 'isDisabled') !== false) {
            return false;
        }

        return ! (
            $action->isConfirmationRequired()
            || $action->shouldOpenModal()
            || filled($action->getUrl())
            || filled($action->getLivewireEventClickHandler())
            || filled($action->getCustomAlpineClickHandler())
        );
    }

    /**
     * Stock defines every structural action's callback inline in
     * `Repeater.php`; a callback declared anywhere else was replaced.
     */
    private static function hasStockActionFunction(Action $action): bool
    {
        $function = $action->getActionFunction();

        if (! $function instanceof Closure) {
            return false;
        }

        return (new ReflectionFunction($function))->getFileName()
            === (new ReflectionClass(Repeater::class))->getFileName();
    }

    /**
     * A hook every action in the panel carries (another plugin's
     * `Action::configureUsing()`, e.g. one setting its tenant context) is part
     * of what "stock" means in this app, not a change made to this repeater's
     * action: it is matched by the source of a freshly configured action's
     * hook, since closures are new objects on every construction.
     */
    private static function isGloballyConfigured(string $property, mixed $value): bool
    {
        if (! $value instanceof Closure) {
            return false;
        }

        $baseline = self::readProperty(self::baseline(), $property);

        return ($baseline instanceof Closure) && (self::sourceOf($baseline) === self::sourceOf($value));
    }

    /**
     * An action carrying nothing but the global configuration. Made afresh on
     * every ask, never cached: a panel's plugins register theirs when the panel
     * boots, and a baseline built before that would miss them.
     */
    private static function baseline(): Action
    {
        return Action::make('villament-baseline');
    }

    private static function sourceOf(Closure $closure): string
    {
        $function = new ReflectionFunction($closure);

        return $function->getFileName().':'.$function->getStartLine();
    }

    private static function readProperty(Action $action, string $property): mixed
    {
        if (! property_exists($action, $property)) {
            return null;
        }

        $reflection = new ReflectionProperty($action, $property);

        return $reflection->isInitialized($action) ? $reflection->getValue($action) : null;
    }
}
