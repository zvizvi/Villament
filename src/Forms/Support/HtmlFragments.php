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
     * The offset just past the opening tag `$html` starts with.
     */
    public static function openingTagEnd(string $html): ?int
    {
        return self::tagEnd($html, 0);
    }

    /**
     * Elements that have no closing tag.
     */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * The direct child elements of the element `$html` starts with, as
     * `[start, end]` offsets into `$html`. Text between them is skipped.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function children(string $html): array
    {
        $offset = self::tagEnd($html, 0);
        $children = [];

        while (($offset !== null) && (($start = strpos($html, '<', $offset)) !== false)) {
            if (($html[$start + 1] ?? '') === '/') {
                break;
            }

            if (! preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $match, offset: $start)) {
                $offset = $start + 1;

                continue;
            }

            $end = in_array(strtolower($match[1]), self::VOID_ELEMENTS, strict: true)
                ? self::tagEnd($html, $start)
                : self::closingOffset($html, $start, $match[1]);

            if ($end === null) {
                break;
            }

            $children[] = [$start, $end];
            $offset = $end;
        }

        return $children;
    }

    /**
     * The closest `<$tag>` element that opens before `$needle` and encloses
     * it.
     */
    public static function enclosing(string $html, string $needle, string $tag): ?string
    {
        $position = strpos($html, $needle);

        if ($position === false) {
            return null;
        }

        $before = substr($html, 0, $position);

        if (! preg_match_all('/<'.preg_quote($tag, '/').'[\s>\/]/', $before, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach (array_reverse($matches[0]) as [, $start]) {
            $end = self::closingOffset($html, $start, $tag);

            if (($end !== null) && ($end > $position)) {
                return substr($html, $start, $end - $start);
            }
        }

        return null;
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
     * The whole element whose opening tag contains `$needle`, whatever its
     * tag name (a schema component wrapper is a `<div>`, or a `<fieldset>`
     * when it has a client-side visibility condition).
     */
    public static function elementOpenedBy(string $html, string $needle): ?string
    {
        $start = self::tagStart($html, $needle);

        if (($start === null) || (! preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $match, offset: $start))) {
            return null;
        }

        $end = self::closingOffset($html, $start, $match[1]);

        return $end === null ? null : substr($html, $start, $end - $start);
    }

    /**
     * Inserts `$before` in front of the first tag containing `$needle` at or
     * after `$offset`, and `$attributes` inside that tag right after its
     * name. Returns the HTML unchanged when there is no such tag.
     */
    public static function decorateTag(string $html, int $offset, string $needle, string $attributes, string $before = ''): string
    {
        $position = strpos($html, $needle, $offset);

        if ($position === false) {
            return $html;
        }

        $start = strrpos(substr($html, 0, $position), '<');

        if (($start === false) || (! preg_match('/\G<[a-zA-Z][a-zA-Z0-9-]*/', $html, $match, offset: $start))) {
            return $html;
        }

        $nameEnd = $start + strlen($match[0]);

        return substr($html, 0, $start).$before.substr($html, $start, $nameEnd - $start).$attributes.substr($html, $nameEnd);
    }

    /**
     * Collapses whitespace BETWEEN tags to one space, outside `<pre>`,
     * `<textarea>` and `<script>` blocks. Rendering is unchanged — between
     * tags the browser already treats any whitespace run as one space — and
     * Filament's indented PHP markup shrinks by a third or more.
     *
     * Nothing inside a tag is touched: attribute values hold Alpine
     * expressions that rely on newlines (no semicolons, `//` comments), and
     * collapsing them breaks the JavaScript.
     */
    public static function collapseWhitespace(string $html): string
    {
        $parts = preg_split('/(<(pre|textarea|script)\b[\s\S]*?<\/\2>)/i', $html, flags: PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $collapsed = '';

        foreach ($parts as $index => $part) {
            // [text, block, tag name, text, block, tag name, ...]
            $collapsed .= match ($index % 3) {
                // Runs at a part's edges sit between a tag and a protected
                // block's tag, so they collapse too.
                0 => preg_replace(['/^\s+$/', '/>\s+</', '/>\s+$/', '/^\s+</'], [' ', '> <', '> ', ' <'], $part) ?? $part,
                1 => $part,
                default => '',
            };
        }

        return $collapsed;
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
