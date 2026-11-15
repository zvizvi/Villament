<?php

declare(strict_types=1);

use Zvizvi\Villament\Forms\Support\HtmlFragments;

it('cuts a balanced element by a needle in its opening tag', function (): void {
    $html = '<ul><li x-sortable-item="a"><ul><li>nested</li></ul></li><li class="sep">-</li><li x-sortable-item="b"></li></ul>';

    expect(HtmlFragments::element($html, 'x-sortable-item="a"', 'li'))->toBe('<li x-sortable-item="a"><ul><li>nested</li></ul></li>')
        ->and(HtmlFragments::elementAfter($html, 'x-sortable-item="a"', 'li'))->toBe('<li class="sep">-</li>')
        ->and(HtmlFragments::elementAfter($html, 'x-sortable-item="b"', 'li'))->toBeNull()
        ->and(HtmlFragments::element($html, 'missing', 'li'))->toBeNull();
});

it('reads the tag name of the element it cuts', function (): void {
    $html = '<div><fieldset disabled wire:partial="schema-component::a"><div>x</div></fieldset></div>';

    expect(HtmlFragments::elementOpenedBy($html, 'wire:partial="schema-component::a"'))->toBe('<fieldset disabled wire:partial="schema-component::a"><div>x</div></fieldset>');
});

it('matches a class as a whole class name only', function (): void {
    $html = '<div class="fi-fo-repeater-add-between">no</div><div class="x fi-fo-repeater-add fi-align-end">yes</div>';

    expect(HtmlFragments::elementWithClass($html, 'fi-fo-repeater-add', 'div'))->toBe('<div class="x fi-fo-repeater-add fi-align-end">yes</div>');
});

it('honours quoted attribute values when finding the end of a tag', function (): void {
    $html = '<div data-reorder-owner x-data="{ a: \'>\' }"><span>x</span></div>';

    expect(HtmlFragments::openingTag($html, 'data-reorder-owner'))->toBe('<div data-reorder-owner x-data="{ a: \'>\' }">')
        ->and(HtmlFragments::insertAfterOpeningTag($html, 'data-reorder-owner', '<i></i>'))->toBe('<div data-reorder-owner x-data="{ a: \'>\' }"><i></i><span>x</span></div>');
});

it('decorates the first matching tag after an offset', function (): void {
    $html = '<li x-sortable-item="a"><div class="content">a</div></li><li x-sortable-item="b"><div class="content">b</div></li>';
    $offset = strpos($html, 'x-sortable-item="b"');

    expect(HtmlFragments::decorateTag($html, $offset, 'class="content"', ' wire:ignore', before: '<template></template>'))
        ->toBe('<li x-sortable-item="a"><div class="content">a</div></li><li x-sortable-item="b"><template></template><div wire:ignore class="content">b</div></li>');
});

it('collapses whitespace between tags only, never inside a tag or a pre/textarea/script', function (): void {
    $html = "<div>\n    <span\n        x-init=\"let a = 1\n        let b = 2 // ASI\n        run(a, b)\"\n    >x</span>\n    <textarea>  keep\n  </textarea>\n    <pre>\n  <b>a</b>\n  </pre>\n</div>";

    expect(HtmlFragments::collapseWhitespace($html))->toBe("<div> <span\n        x-init=\"let a = 1\n        let b = 2 // ASI\n        run(a, b)\"\n    >x</span> <textarea>  keep\n  </textarea> <pre>\n  <b>a</b>\n  </pre> </div>");
});
