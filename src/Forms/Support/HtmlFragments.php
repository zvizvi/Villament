<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Forms\Support;

/**
 * Cuts fragments out of HTML that Filament itself rendered.
 *
 * This is not a general HTML parser: it relies on what Filament's renderers
 * guarantee — attribute values are escaped (`e()`, `Js::from()` turn `<` and
 * `>` into entities or `<`), so a `<` only ever opens or closes a tag,
 * and elements are balanced. `DOMDocument` is not an option: libxml's HTML 4
 * parser mangles Alpine attribute names such as `x-on:click.stop`.
 */
final class HtmlFragments
{
    /**
     * The whole `<$tag>` element whose opening tag contains `$needle`.
     */
    public static function element(string $html, string $needle, string $tag): ?string
    {
        $bounds = self::elementBounds($html, $needle, $tag);

        return $bounds === null ? null : substr($html, $bounds[0], $bounds[1] - $bounds[0]);
    }

    /**
     * The first `<$tag>` element carrying `$class` as a whole class name.
     */
    public static function elementWithClass(string $html, string $class, string $tag): ?string
    {
        $pattern = '/<'.preg_quote($tag, '/').'\s[^>]*?class="(?:[^"]*\s)?'.preg_quote($class, '/').'(?:\s[^"]*)?"/';

        if (! preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1];
        $end = self::closingOffset($html, $start, $tag);

        return $end === null ? null : substr($html, $start, $end - $start);
    }

    /**
     * The `<$tag>` sibling directly following the element that `$needle`
     * opens, or null when the next thing is not a `<$tag>`.
     */
    public static function elementAfter(string $html, string $needle, string $tag): ?string
    {
        $bounds = self::elementBounds($html, $needle, $tag);

        if ($bounds === null) {
            return null;
        }

        $offset = $bounds[1];

        while (($offset < strlen($html)) && ctype_space($html[$offset])) {
            $offset++;
        }

        if (! self::opensTagAt($html, $offset, $tag)) {
            return null;
        }

        $end = self::closingOffset($html, $offset, $tag);

        return $end === null ? null : substr($html, $offset, $end - $offset);
    }

    /**
     * Just the opening tag that contains `$needle`.
     */
    public static function openingTag(string $html, string $needle): ?string
    {
        $start = self::tagStart($html, $needle);

        if ($start === null) {
            return null;
        }

        $end = self::tagEnd($html, $start);

        return $end === null ? null : substr($html, $start, $end - $start);
    }

    /**
     * Inserts `$insert` right after the first opening tag containing `$needle`.
     */
    public static function insertAfterOpeningTag(string $html, string $needle, string $insert): string
    {
        $start = self::tagStart($html, $needle);
        $end = $start === null ? null : self::tagEnd($html, $start);

        if ($end === null) {
            return $html;
        }

        return substr($html, 0, $end).$insert.substr($html, $end);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function elementBounds(string $html, string $needle, string $tag): ?array
    {
        $start = self::tagStart($html, $needle);

        if (($start === null) || (! self::opensTagAt($html, $start, $tag))) {
            return null;
        }

        $end = self::closingOffset($html, $start, $tag);

        return $end === null ? null : [$start, $end];
    }

    private static function tagStart(string $html, string $needle): ?int
    {
        $position = strpos($html, $needle);

        if ($position === false) {
            return null;
        }

        $start = strrpos(substr($html, 0, $position), '<');

        return $start === false ? null : $start;
    }

    private static function opensTagAt(string $html, int $offset, string $tag): bool
    {
        return (bool) preg_match('/\G<'.preg_quote($tag, '/').'[\s>\/]/', $html, offset: $offset);
    }

    /**
     * The offset just past the `>` that ends the tag opened at `$start`,
     * honouring quoted attribute values.
     */
    private static function tagEnd(string $html, int $start): ?int
    {
        $quote = null;
        $length = strlen($html);

        for ($i = $start + 1; $i < $length; $i++) {
            $character = $html[$i];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($character === '"') || ($character === "'")) {
                $quote = $character;

                continue;
            }

            if ($character === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The offset just past the `</$tag>` that balances the tag opened at
     * `$start`.
     */
    private static function closingOffset(string $html, int $start, string $tag): ?int
    {
        $pattern = '/<'.preg_quote($tag, '/').'[\s>\/]|<\/'.preg_quote($tag, '/').'\s*>/i';

        if (! preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $start)) {
            return null;
        }

        $depth = 0;

        foreach ($matches[0] as [$match, $offset]) {
            if (str_starts_with($match, '</')) {
                $depth--;

                if ($depth === 0) {
                    return $offset + strlen($match);
                }

                continue;
            }

            $depth++;
        }

        return null;
    }
}
