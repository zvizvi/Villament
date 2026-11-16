<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;

/*
 * Shared by the ClientRepeater suites: reading the template meta a page
 * rendered, and replaying what the Alpine component does to the state.
 */

/**
 * The template meta rendered for the repeater whose id is `form.{$name}`,
 * or null when that repeater rendered no template.
 *
 * @return array<string, mixed>|null
 */
function templateMeta(string $html, string $name): ?array
{
    $rootTag = '/<div [^>]*id="form\.'.preg_quote($name, '/').'"[^>]*data-reorder-owner[^>]*>/';

    if (! preg_match($rootTag, $html, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("Repeater [{$name}] was not rendered.");
    }

    $after = substr($html, $match[0][1] + strlen($match[0][0]));

    if (! str_starts_with($after, '<template data-villament-repeater-template')) {
        return null;
    }

    preg_match('/<script type="application\/json" data-villament-repeater-meta>(.*?)<\/script>/s', $after, $script);

    return json_decode($script[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * `mapTokens()` + `replaceStateTokens()` + `insertItem()`.
 *
 * @param  array<string, mixed>  $items
 * @param  array<string, mixed>  $meta
 * @return array{0: array<string, mixed>, 1: string}
 */
function clientAdd(array $items, array $meta, ?string $afterKey = null): array
{
    $replacements = [];

    foreach ($meta['tokens'] as $token) {
        $replacements[$token['token']] = (string) Str::uuid();
    }

    $key = $replacements[$meta['token']];
    $state = json_decode(strtr(json_encode($meta['state'], JSON_THROW_ON_ERROR), $replacements), true);

    if ($afterKey === null) {
        $items[$key] = $state;

        return [$items, $key];
    }

    $next = [];

    foreach ($items as $itemKey => $item) {
        $next[$itemKey] = $item;

        if ($itemKey === $afterKey) {
            $next[$key] = $state;
        }
    }

    return [$next, $key];
}

/**
 * `moveItem()`: swap with the neighbour.
 *
 * @param  array<string, mixed>  $items
 * @return array<string, mixed>
 */
function clientMove(array $items, string $key, string $direction): array
{
    $keys = array_keys($items);
    $index = array_search($key, $keys, strict: true);
    $target = $direction === 'up' ? $index - 1 : $index + 1;

    [$keys[$index], $keys[$target]] = [$keys[$target], $keys[$index]];

    return array_combine($keys, array_map(fn (string $key): mixed => $items[$key], $keys));
}

/**
 * @return array<string, mixed>
 */
function items(Testable $component, string $name): array
{
    return $component->get("data.{$name}");
}

function stockAction(Testable $component, string $name, string $action, array $arguments = []): Testable
{
    return $component->callAction(TestAction::make($action)->schemaComponent($name)->arguments($arguments));
}
