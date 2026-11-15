<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms;

/**
 * The placeholder keys a `ClientRepeater` template is rendered under.
 *
 * The main token stands for the new item's own key. Every nested item key
 * in the template item's state (the items a nested repeater's
 * `defaultItems()` hydrated, found by walking the schema) is renamed to a
 * token too, so that each copy the browser makes gets fresh keys at every
 * level, not just the outer one.
 *
 * Tokens are deterministic per state path: an unchanged template renders
 * byte-identically on every request, which keeps its `wire:key` stable.
 * They are fixed-width, so no token is a prefix of another.
 */
final class ClientTemplateTokens
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string, string> original UUID => token */
    private array $nested = [];

    /**
     * @param  list<string>  $itemKeys  The keys that ARE nested repeater /
     *                                  builder items (found by walking the item's schema). Only those
     *                                  are renamed: a UUID-shaped key in DATA (a KeyValue field, an
     *                                  array default) is the app's and stays as it is.
     */
    public function __construct(
        private readonly string $mainToken,
        private readonly string $statePath,
        private readonly array $itemKeys = [],
    ) {}

    public static function mainToken(string $statePath): string
    {
        return self::token($statePath, 0);
    }

    public static function secondToken(string $statePath): string
    {
        return self::token($statePath, 9999);
    }

    private static function token(string $statePath, int $index): string
    {
        return 'villament-'.substr(md5($statePath), 0, 16).'-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Renames every nested UUID key in the state, and in the HTML, to a
     * token — including the `md5()` of each key that Filament's reordering
     * markup derives element ids from.
     *
     * @param  array<mixed>  $state
     * @return array{0: string, 1: array<mixed>}
     */
    public function normalize(string $html, array $state): array
    {
        $state = $this->renameKeys($state);

        $search = [];
        $replace = [];

        foreach ($this->nested as $uuid => $token) {
            $search[] = $uuid;
            $replace[] = $token;
            $search[] = md5($uuid);
            $replace[] = md5($token);
        }

        return [$search === [] ? $html : str_replace($search, $replace, $html), $state];
    }

    /**
     * Every token with the hash the browser has to swap alongside it.
     *
     * @return list<array{token: string, hash: string}>
     */
    public function all(): array
    {
        return array_map(
            fn (string $token): array => ['token' => $token, 'hash' => md5($token)],
            [$this->mainToken, ...array_values($this->nested)],
        );
    }

    /**
     * @param  array<mixed>  $state
     * @return array<mixed>
     */
    private function renameKeys(array $state): array
    {
        $renamed = [];

        foreach ($state as $key => $value) {
            if (is_string($key) && preg_match(self::UUID_PATTERN, $key) && in_array($key, $this->itemKeys, strict: true)) {
                $key = $this->nested[$key] ??= self::token($this->statePath, count($this->nested) + 1);
            }

            $renamed[$key] = is_array($value) ? $this->renameKeys($value) : $value;
        }

        return $renamed;
    }
}
