<?php

declare(strict_types=1);

use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Zvizvi\Villament\Forms\VillamentRepeater;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\RepeaterForm;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create());
    RepeaterForm::resetFixture();
});

afterEach(fn () => RepeaterForm::resetFixture());

const TOKEN = VillamentRepeater::BLUEPRINT_ITEM_KEY;

/**
 * The fixture's repeater. `form` is the schema key, `members` the state path,
 * so `form.members` is what `getKey()` composes.
 */
const REPEATER_KEY = 'form.members';

/** @param  (Closure(VillamentRepeater): VillamentRepeater)|null  $build */
function repeaterHtml(?Closure $build = null, array $state = []): string
{
    RepeaterForm::$build = $build;
    RepeaterForm::$initialState = $state;

    return livewire(RepeaterForm::class)->html();
}

/** The `x-data` config the island's Alpine component is booted with. */
function repeaterConfig(string $html): array
{
    expect($html)->toMatch('/villamentRepeater\(JSON\.parse\(/');

    preg_match("/villamentRepeater\(JSON\.parse\('(.*?)'\)\)/", $html, $matches);

    return json_decode(
        json_decode('"'.str_replace("\\'", "'", $matches[1]).'"'),
        associative: true,
    );
}

/**
 * Just one repeater's blueprint markup.
 *
 * Naive about nesting on purpose — a nested repeater renders a blueprint of its
 * own, and the first `</template>` then closes the inner one. The nesting tests
 * read the whole document instead.
 */
function repeaterBlueprint(string $html, string $key = REPEATER_KEY): string
{
    preg_match(
        '/<template wire:ignore data-villament-repeater-blueprint="'.preg_quote($key, '/').'">(.*?)<\/template>/s',
        $html,
        $matches,
    );

    return $matches[1] ?? '';
}

describe('the client-side handlers replace the Livewire ones', function (): void {
    it('drives add, delete, clone and move from Alpine, not from mountAction', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->cloneable()->reorderableWithButtons(),
            ['members' => [['name' => 'Ada'], ['name' => 'Grace']]],
        );

        expect($html)
            ->toContain('x-on:click="addItem()"')
            ->toContain('x-on:click="deleteItem($el)"')
            ->toContain('x-on:click="cloneItem($el)"')
            ->toContain('x-on:click="moveItem($el, -1)"')
            ->toContain('x-on:click="moveItem($el, 1)"');
    });

    it('leaves no Livewire click handler on the buttons it took over', function (): void {
        $html = repeaterHtml(state: ['members' => [['name' => 'Ada']]]);

        // `alpineClickHandler()` calls `livewireClickHandlerEnabled(false)`.
        expect($html)
            ->not->toContain('mountAction(\'add\'')
            ->not->toContain('mountAction(\'delete\'');
    });

    it('cancels the drag round trip with a capture listener instead of editing stock markup', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->reorderable(),
            ['members' => [['name' => 'Ada'], ['name' => 'Grace']]],
        );

        // Stock's own handler stays on the list; ours runs first, in the
        // capture phase, and stops the event before it can reach that one.
        expect($html)
            ->toContain('x-on:end.capture="reorderFromSortable($event)"')
            ->toContain('x-on:end.stop');
    });

    it('marks the move buttons so the browser can maintain their disabled state', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->reorderableWithButtons(),
            ['members' => [['name' => 'Ada'], ['name' => 'Grace']]],
        );

        expect($html)
            ->toContain('data-villament-move="up"')
            ->toContain('data-villament-move="down"');
    });

    it('leaves a tooltipped move button on stock, because Filament strips its x-on when disabled', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater
                ->reorderableWithButtons()
                ->moveUpAction(fn ($action) => $action->tooltip('Up')),
            ['members' => [['name' => 'Ada'], ['name' => 'Grace']]],
        );

        expect($html)
            ->not->toContain('moveItem($el, -1)')
            ->toContain('moveItem($el, 1)');
    });

    it('leaves an action that opens a modal on the server, where its guard lives', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater
                ->deleteAction(fn ($action) => $action->requiresConfirmation()),
            ['members' => [['name' => 'Ada']]],
        );

        expect($html)
            ->not->toContain('deleteItem($el)')
            ->toContain('x-on:click="addItem()"');
    });
});

describe('the blueprint', function (): void {
    it('ships one inert item under a template Livewire will not morph', function (): void {
        $html = repeaterHtml(state: ['members' => [['name' => 'Ada']]]);

        expect($html)->toContain(
            '<template wire:ignore data-villament-repeater-blueprint="'.REPEATER_KEY.'">',
        );
    });

    it('carries the token everywhere a real item key would appear', function (): void {
        $blueprint = repeaterBlueprint(repeaterHtml(state: ['members' => [['name' => 'Ada']]]));

        expect($blueprint)
            ->toContain('x-sortable-item="'.TOKEN.'"')
            ->toContain('wire:model="data.members.'.TOKEN.'.name"')
            ->toContain('.'.REPEATER_KEY.'.'.TOKEN.'.item"'); // the `wire:key`
    });

    it('is rendered once per repeater, not once per pass over its attributes', function (): void {
        $html = repeaterHtml(state: ['members' => [['name' => 'Ada'], ['name' => 'Grace']]]);

        expect(substr_count($html, 'data-villament-repeater-blueprint='))->toBe(1);
    });

    it('holds exactly one item, whatever the repeater already has', function (): void {
        $blueprint = repeaterBlueprint(repeaterHtml(
            state: ['members' => [['name' => 'Ada'], ['name' => 'Grace'], ['name' => 'Anita']]],
        ));

        expect(substr_count($blueprint, 'x-sortable-item='))->toBe(1);
    });

    it('brings the items container along, so the first add has somewhere to go', function (): void {
        $blueprint = repeaterBlueprint(repeaterHtml(state: ['members' => []]));

        expect($blueprint)
            ->toContain('fi-fo-repeater-items')
            ->toContain('x-sortable-item="'.TOKEN.'"');
    });

    it('keeps its scratch item out of the component state and out of the snapshot', function (): void {
        $component = livewire(RepeaterForm::class);

        preg_match('/wire:snapshot="(.*?)" wire:effects/s', $component->html(), $matches);

        expect($component->get('data'))->not->toHaveKey(TOKEN)
            ->and($component->get('data.members') ?? [])->not->toHaveKey(TOKEN)
            ->and($matches[1] ?? '')->not->toContain(TOKEN);
    });

    it('expands a new row even when the repeater collapses its items by default', function (): void {
        $blueprint = repeaterBlueprint(repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->collapsed(),
            ['members' => [['name' => 'Ada']]],
        ));

        expect($blueprint)->toContain('isCollapsed: false');
    });
});

describe('the Alpine scope', function (): void {
    it('carries the state path, blueprint key, token and item limit', function (): void {
        $config = repeaterConfig(repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->maxItems(3),
        ));

        expect($config)->toMatchArray([
            'statePath' => 'data.members',
            'blueprintKey' => REPEATER_KEY,
            'itemKey' => TOKEN,
            'maxItems' => 3,
        ]);
    });

    it('carries the defaults a fresh item is filled with', function (): void {
        $config = repeaterConfig(repeaterHtml(fn (VillamentRepeater $repeater) => $repeater->schema([
            TextInput::make('name')->default('Unnamed'),
        ])));

        expect($config['itemDefaults'])->toBe(['name' => 'Unnamed']);
    });

    it('is absent when the repeater degrades to stock', function (): void {
        $html = repeaterHtml(fn (VillamentRepeater $repeater) => $repeater->clientSideItems(false));

        expect($html)
            ->not->toContain('villamentRepeater(')
            ->not->toContain('data-villament-repeater-blueprint');
    });
});

describe('the gate degrades to stock rather than guessing', function (): void {
    $degrades = [
        'a live repeater' => fn (VillamentRepeater $r) => $r->live(),
        'an afterStateUpdated hook' => fn (VillamentRepeater $r) => $r->afterStateUpdated(fn () => null),
        'item numbers' => fn (VillamentRepeater $r) => $r->itemLabel(fn () => 'x')->itemNumbers(),
        'an index-dependent item label' => fn (VillamentRepeater $r) => $r->itemLabel(fn (int $index) => "#{$index}"),
        'a visible add-between action' => fn (VillamentRepeater $r) => $r->addBetweenAction(fn ($action) => $action->visible()),
        'a label between items' => fn (VillamentRepeater $r) => $r->labelBetweenItems('and'),
        'the table layout' => fn (VillamentRepeater $r) => $r->table([TableColumn::make('Name')]),
    ];

    foreach ($degrades as $description => $build) {
        it("degrades for {$description}", function () use ($build): void {
            $html = repeaterHtml($build, ['members' => [['name' => 'Ada']]]);

            expect($html)
                ->not->toContain('villamentRepeater(')
                ->not->toContain('data-villament-repeater-blueprint')
                ->not->toContain('addItem()');
        });
    }

    it('stays client-side for an item label that does not depend on position', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->itemLabel(fn (array $state) => $state['name'] ?? null),
            ['members' => [['name' => 'Ada']]],
        );

        expect($html)->toContain('villamentRepeater(');
    });

    it('honours an explicit opt-in over the gate', function (): void {
        $html = repeaterHtml(fn (VillamentRepeater $repeater) => $repeater
            ->itemLabel(fn () => 'x')
            ->itemNumbers()
            ->clientSideItems(true));

        expect($html)->toContain('villamentRepeater(');
    });

    it('resets the gate when afterStateUpdated is cleared, as the parent does', function (): void {
        $html = repeaterHtml(fn (VillamentRepeater $repeater) => $repeater
            ->afterStateUpdated(fn () => null)
            ->afterStateUpdated(null));

        expect($html)->toContain('villamentRepeater(');
    });
});

describe('nesting', function (): void {
    it('gives an inner repeater its own blueprint, without recursing', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->schema([
                TextInput::make('name'),
                VillamentRepeater::make('tasks')->schema([TextInput::make('title')]),
            ]),
            ['members' => [['name' => 'Ada', 'tasks' => [['title' => 'Analytical engine']]]]],
        );

        // One for the outer repeater, one for the inner one inside the real
        // row, and one for the inner one inside the outer's blueprint.
        expect(substr_count($html, 'data-villament-repeater-blueprint='))->toBe(3);
    });

    it('keys the inner blueprint by the outer token, so a stamped row still finds it', function (): void {
        $html = repeaterHtml(
            fn (VillamentRepeater $repeater) => $repeater->schema([
                VillamentRepeater::make('tasks')->schema([TextInput::make('title')]),
            ]),
            ['members' => [['tasks' => []]]],
        );

        // Stamping the outer row rewrites the token in the inner blueprint's
        // key and in the inner component's own config alike, so the two still
        // point at each other afterwards.
        expect($html)->toContain(
            'data-villament-repeater-blueprint="'.REPEATER_KEY.'.'.TOKEN.'.tasks"',
        );

        // The inner component's own `x-data` sits inside the outer
        // blueprint's markup, carrying the same token in both halves of the
        // pairing — the key it looks the template up by, and the state path it
        // writes to.
        expect(repeaterConfig(repeaterBlueprint($html)))->toMatchArray([
            'blueprintKey' => REPEATER_KEY.'.'.TOKEN.'.tasks',
            'statePath' => 'data.members.'.TOKEN.'.tasks',
        ]);
    });
});

describe('the server keeps its half of the contract', function (): void {
    it('hydrates an item the browser added, with no knowledge that it was', function (): void {
        RepeaterForm::$initialState = ['members' => []];

        $component = livewire(RepeaterForm::class)
            ->set('data.members', ['9f1cclientmade' => ['name' => 'Added in the browser']]);

        expect($component->get('data.members.9f1cclientmade.name'))
            ->toBe('Added in the browser')
            ->and($component->html())
            ->toContain('wire:model="data.members.9f1cclientmade.name"');
    });

    it('renders reordered keys in their new order', function (): void {
        RepeaterForm::$initialState = ['members' => []];

        $html = livewire(RepeaterForm::class)
            ->set('data.members', ['b' => ['name' => 'Second'], 'a' => ['name' => 'First']])
            ->html();

        expect(strpos($html, 'x-sortable-item="b"'))
            ->toBeLessThan(strpos($html, 'x-sortable-item="a"'));
    });
});
