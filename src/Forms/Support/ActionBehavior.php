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
            if (self::readProperty($action, $property) !== null) {
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

    private static function readProperty(Action $action, string $property): mixed
    {
        if (! property_exists($action, $property)) {
            return null;
        }

        $reflection = new ReflectionProperty($action, $property);

        return $reflection->isInitialized($action) ? $reflection->getValue($action) : null;
    }
}
