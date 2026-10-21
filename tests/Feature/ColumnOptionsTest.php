<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * The per-column options from the Columns "Overview" docs that the island has
 * to carry across the wire, rather than silently drop.
 */
beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

afterEach(function (): void {
    UserResource::$searchDebounce = null;
    UserResource::$searchOnBlur = false;
    UserResource::$searchPlaceholder = null;
    UserResource::$persistsInSession = false;
    UserResource::$hasLoadingSkeleton = false;
});

/**
 * The island config, read back out of the rendered page.
 *
 * @return array<string, mixed>
 */
function columnOptionsConfig(): array
{
    $html = livewire(VueListUsers::class)->html();
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed> The named record's cell payloads.
 */
function columnOptionsCellsFor(string $name): array
{
    return getJson('/admin/villament/users/data?columnSearches[name]='.urlencode($name))
        ->assertOk()
        ->json('records.0.cells');
}

describe('utility injection', function (): void {
    it('injects a real $rowLoop into column closures', function (): void {
        User::factory()->create(['name' => 'Loop A']);
        User::factory()->create(['name' => 'Loop B']);
        User::factory()->create(['name' => 'Loop C']);

        // The fixture's `tooltip()` closure reads `$rowLoop->iteration` and
        // `$rowLoop->count` — without the seed it would fatal, and with a
        // wrong one it would report the wrong row.
        $records = getJson('/admin/villament/users/data?columnSearches[name]=Loop&sort=name:asc')
            ->assertOk()
            ->json('records');

        expect($records)->toHaveCount(3)
            ->and($records[0]['cells']['name']['tooltip'])->toBe('Row 1 of 3: Loop A')
            ->and($records[1]['cells']['name']['tooltip'])->toBe('Row 2 of 3: Loop B')
            ->and($records[2]['cells']['name']['tooltip'])->toBe('Row 3 of 3: Loop C');
    });

    it('counts the rows of the PAGE, exactly as the blade loop does', function (): void {
        User::factory()->count(3)->create(['status' => 'pending']);

        $records = getJson('/admin/villament/users/data?perPage=5')->assertOk()->json('records');

        // 3 created + the acting user = 4 rows on this page.
        expect($records)->toHaveCount(4)
            ->and($records[0]['cells']['name']['tooltip'])->toContain('Row 1 of 4')
            ->and($records[3]['cells']['name']['tooltip'])->toContain('Row 4 of 4');
    });
});

describe('tooltips', function (): void {
    it('serializes the cell tooltip, the empty tooltip and the header tooltip', function (): void {
        $user = User::factory()->create(['name' => 'Tooltipped', 'is_admin' => null]);

        $cells = columnOptionsCellsFor('Tooltipped');

        expect($cells['name']['tooltip'])->toContain('Tooltipped')
            // A blank icon cell swaps `tooltip()` for `emptyTooltip()`.
            ->and($cells['is_admin']['empty'])->toBeTrue()
            ->and($cells['is_admin']['tooltip'])->toBe('This user has no role set')
            ->and($cells['avatar_url']['tooltip'])->toBe('Profile picture');

        $column = collect(columnOptionsConfig()['schema']['columns'])->firstWhere('key', 'name');

        expect($column['headerTooltip'])->toBe("The user's full name");

        unset($user);
    });
});

describe('placeholders', function (): void {
    it('renders the placeholder on blank icon, image and text cells', function (): void {
        User::factory()->create([
            'name' => 'Blank Everything',
            'is_admin' => null,
            'avatar_url' => null,
            'team_id' => null,
        ]);

        $cells = columnOptionsCellsFor('Blank Everything');

        expect($cells['is_admin'])->toMatchArray(['empty' => true, 'placeholder' => 'No role'])
            ->and($cells['avatar_url'])->toMatchArray(['empty' => true, 'placeholder' => 'No avatar'])
            ->and($cells['team.name'])->toMatchArray(['empty' => true, 'placeholder' => 'No team']);
    });
});

describe('descriptions', function (): void {
    it('carries the above-positioned description alongside the below one', function (): void {
        User::factory()->create(['name' => 'Described']);

        expect(columnOptionsCellsFor('Described')['name']['descriptionAbove'])->toBe('Primary contact');
    });
});

describe('extra attributes', function (): void {
    it('ships extraAttributes and extraCellAttributes per record, and extraHeaderAttributes per column', function (): void {
        User::factory()->create(['name' => 'Attributed']);

        $cell = columnOptionsCellsFor('Attributed')['name'];

        expect($cell['extraAttributes'])->toBe(['data-name-cell' => 'yes'])
            ->and($cell['extraCellAttributes'])->toBe(['data-td' => 'name']);

        $column = collect(columnOptionsConfig()['schema']['columns'])->firstWhere('key', 'name');

        expect($column['extraHeaderAttributes'])->toBe(['data-th' => 'name']);
    });

    it('omits the keys entirely for a column with no extra attributes', function (): void {
        User::factory()->create(['name' => 'Plain']);

        expect(columnOptionsCellsFor('Plain')['logins_count'])
            ->not->toHaveKey('extraAttributes')
            ->not->toHaveKey('extraCellAttributes');
    });
});

describe('cell click model', function (): void {
    it('resolves the wrapper element server-side, per stock precedence', function (): void {
        $team = Team::query()->create(['name' => 'Clickers', 'slug' => 'clickers']);
        // `is_admin` is pinned: the factory randomizes it, the policy denies
        // `update` to admins, and ListRecords derives the RECORD url from that
        // — so an admin record would have no url and every wrapper below would
        // collapse to a div.
        User::factory()->create(['name' => 'Clickable', 'team_id' => $team->id, 'is_admin' => false]);

        $cells = columnOptionsCellsFor('Clickable');

        // Column url wins and is flagged as a COLUMN url.
        expect($cells['name'])->toMatchArray(['wrapper' => 'a', 'hasColumnUrl' => true])
            ->and($cells['name']['url'])->toContain('/admin/users/')
            // `disabledClick()` forces a plain div even though the record has
            // a url of its own.
            ->and($cells['team.name'])->toMatchArray(['wrapper' => 'div', 'url' => null, 'click' => null])
            // No column url, no column action → the RECORD url, unflagged.
            ->and($cells['logins_count'])->toMatchArray(['wrapper' => 'a', 'hasColumnUrl' => false]);
    });
});

describe('search options', function (): void {
    it('follows the table\'s own searchDebounce, searchOnBlur and searchPlaceholder', function (): void {
        UserResource::$searchDebounce = '1s';
        UserResource::$searchOnBlur = true;
        UserResource::$searchPlaceholder = 'Find a user…';

        $schema = columnOptionsConfig()['schema'];

        expect($schema['debounceMs'])->toBe(1000)
            ->and($schema['searchOnBlur'])->toBeTrue()
            ->and($schema['strings']['searchPlaceholder'])->toBe('Find a user…');
    });

    it('falls back to Filament\'s own 500ms default and translated placeholder', function (): void {
        $schema = columnOptionsConfig()['schema'];

        expect($schema['debounceMs'])->toBe(500)
            ->and($schema['searchOnBlur'])->toBeFalse()
            ->and($schema['strings']['searchPlaceholder'])->toBe(__('filament-tables::table.fields.search.placeholder'));
    });

    it('lets the package config override every table', function (): void {
        config()->set('villament.debounce_ms', 42);
        UserResource::$searchDebounce = '1s';

        expect(columnOptionsConfig()['schema']['debounceMs'])->toBe(42);
    });
});

describe('session persistence', function (): void {
    it('writes the sort and search back to the session the endpoint request', function (): void {
        UserResource::$persistsInSession = true;

        User::factory()->create(['name' => 'Persisted']);

        // The component's own session keys — read them off a real instance.
        $component = livewire(VueListUsers::class)->instance();
        $sortKey = $component->getTableSortSessionKey();
        $searchKey = $component->getTableSearchSessionKey();

        session()->forget([$sortKey, $searchKey]);

        getJson('/admin/villament/users/data?sort=name:desc&search=Persisted')->assertOk();

        expect(session($sortKey))->toBe('name:desc')
            ->and(session($searchKey))->toBe('Persisted');
    });

    it('writes nothing when the table does not opt in', function (): void {
        $component = livewire(VueListUsers::class)->instance();
        $sortKey = $component->getTableSortSessionKey();

        session()->forget($sortKey);

        getJson('/admin/villament/users/data?sort=name:desc')->assertOk();

        expect(session()->has($sortKey))->toBeFalse();
    });
});

describe('list modifiers', function (): void {
    it('slices a limited list server-side and reports what it dropped', function (): void {
        User::factory()->create(['name' => 'Lister']);

        $cell = columnOptionsCellsFor('Lister')['aliases'];

        // Three values, `limitList(2)`: only two ever cross the wire.
        expect($cell['items'])->toBe(['Alpha', 'Beta'])
            ->and($cell['overLimitCount'])->toBe(1)
            ->and($cell['listWithLineBreaks'])->toBeTrue()
            ->and($cell['listExpandable'])->toBeFalse()
            ->and($cell['listLimitMessage'])->toBe(
                trans_choice('filament-tables::table.columns.text.more_list_items', 1),
            );
    });

    it('keeps the overflow for an expandable list, since the reader can ask for it', function (): void {
        User::factory()->create(['name' => 'Expander']);

        $cell = columnOptionsCellsFor('Expander')['nicknames'];

        expect($cell['items'])->toBe(['Alpha', 'Beta', 'Gamma'])
            ->and($cell['listLimit'])->toBe(2)
            ->and($cell['overLimitCount'])->toBe(1)
            ->and($cell['listExpandable'])->toBeTrue()
            ->and($cell['listExpandMessage'])->toBe(
                trans_choice('filament-tables::table.columns.text.actions.expand_list', 1),
            );
    });

    it('adds nothing at all to a cell with no list modifiers', function (): void {
        User::factory()->create(['name' => 'Plain']);

        expect(columnOptionsCellsFor('Plain')['email'])
            ->not->toHaveKey('items')
            ->not->toHaveKey('overLimitCount');
    });

    it('matches stock: same visible values, same overflow message', function (): void {
        User::factory()->create(['name' => 'Parity Lister']);

        $stock = livewire(ListUsers::class)
            ->searchTable('Parity Lister')
            ->html();

        // Both list columns render, so the row carries Alpha/Beta twice — but
        // Gamma exactly once: dropped from the limited list, kept (hidden) by
        // the expandable one. That is the same split the endpoint payload has.
        expect(substr_count($stock, 'Gamma'))->toBe(1)
            ->and($stock)->toContain(
                trans_choice('filament-tables::table.columns.text.more_list_items', 1),
            );

        $cells = columnOptionsCellsFor('Parity Lister');

        expect($cells['aliases']['items'])->not->toContain('Gamma')
            ->and($cells['nicknames']['items'])->toContain('Gamma');
    });
});

describe('inline', function (): void {
    afterEach(function (): void {
        UserResource::$inlineColumns = false;
    });

    it('carries inline() through, so the cell does not double up the padding', function (): void {
        UserResource::$inlineColumns = true;
        User::factory()->create(['name' => 'Inlined']);

        $cells = columnOptionsCellsFor('Inlined');

        // Filament's CSS keys the standalone padding off the ABSENCE of the
        // class, so every cell type that renders its own wrapper needs it.
        expect($cells['name']['inline'])->toBeTrue()
            ->and($cells['status']['inline'])->toBeTrue()
            ->and($cells['avatar_url']['inline'])->toBeTrue();
    });

    it('omits the key entirely when the column is not inline', function (): void {
        User::factory()->create(['name' => 'Blocky']);

        expect(columnOptionsCellsFor('Blocky')['name'])->not->toHaveKey('inline');
    });
});

describe('loading skeleton', function (): void {
    it('carries loadingSkeleton() and the Livewire loading delay to the island', function (): void {
        UserResource::$hasLoadingSkeleton = true;
        config()->set('filament.livewire_loading_delay', 'long');

        $schema = columnOptionsConfig()['schema'];

        expect($schema['loadingSkeleton'])->toBeTrue()
            ->and($schema['loadingDelayMs'])->toBe(300);
    });

    it('is off by default, with Livewire\'s 200ms default delay', function (): void {
        $schema = columnOptionsConfig()['schema'];

        expect($schema['loadingSkeleton'])->toBeFalse()
            ->and($schema['loadingDelayMs'])->toBe(200);
    });

    it('maps the `none` delay to an immediate skeleton', function (): void {
        config()->set('filament.livewire_loading_delay', 'none');

        expect(columnOptionsConfig()['schema']['loadingDelayMs'])->toBe(0);
    });
});
