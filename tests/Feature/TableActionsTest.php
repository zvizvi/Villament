<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Enums\RecordActionsPosition;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

/**
 * Everything the Tables "Actions" docs page promises that the island has to
 * carry across the wire: where actions sit, how their triggers look, and the
 * full record-selection API.
 */
beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

afterEach(function (): void {
    UserResource::$heading = null;
    UserResource::$description = null;
    UserResource::$headerActions = null;
    UserResource::$recordActionsPosition = null;
    UserResource::$recordActionsAlignment = null;
    UserResource::$recordActionsColumnLabel = null;
    UserResource::$modifyUngroupedRecordActions = null;
    UserResource::$selectable = null;
    UserResource::$maxSelectableRecords = null;
    UserResource::$selectCurrentPageOnly = false;
    UserResource::$selectGroupsOnly = false;
    UserResource::$disabledSelection = false;
    UserResource::$checksIfRecordIsSelectable = false;
});

/**
 * The island config, read back out of the rendered page.
 *
 * @return array<string, mixed>
 */
function tableActionsConfig(): array
{
    $html = livewire(VueListUsers::class)->html();
    preg_match('/<script type="application\/json" data-villament-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed> The named record's full payload.
 */
function tableActionsRecord(string $name): array
{
    return getJson('/admin/villament/users/data?columnSearches[name]='.urlencode($name))
        ->assertOk()
        ->json('records.0');
}

describe('header actions', function (): void {
    it('serializes the table heading, description and header-actions position', function (): void {
        UserResource::$heading = 'All users';
        UserResource::$description = 'Everyone with an account';

        $header = tableActionsConfig()['schema']['header'];

        expect($header['heading'])->toBe('All users')
            ->and($header['description'])->toBe('Everyone with an account')
            // Filament's default when `headerActionsPosition()` is never called.
            ->and($header['actionsPosition'])->toBe('Adaptive');
    });

    it('serializes header actions in definition order, flagging the bulk ones', function (): void {
        UserResource::$headerActions = fn (): array => [
            CreateAction::make(),
            BulkAction::make('archiveSelected')->action(fn () => null),
        ];

        $header = tableActionsConfig()['schema']['actions']['header'];

        expect($header)->toHaveCount(2)
            ->and($header[0]['name'])->toBe('create')
            ->and($header[0]['bulk'])->toBeFalse()
            ->and($header[1]['name'])->toBe('archiveSelected')
            // Stock hides a bulk trigger until something is selected
            // (`x-show="getSelectedRecordsCount()"`); the flag is what drives
            // the same thing in the island.
            ->and($header[1]['bulk'])->toBeTrue();
    });

    it('marks a whole BulkActionGroup in the header as bulk, and a mixed group as not', function (): void {
        UserResource::$headerActions = fn (): array => [
            BulkActionGroup::make([
                BulkAction::make('bulkOne')->action(fn () => null),
            ]),
            ActionGroup::make([
                Action::make('plainOne')->action(fn () => null),
                BulkAction::make('bulkTwo')->action(fn () => null),
            ]),
        ];

        $header = tableActionsConfig()['schema']['actions']['header'];

        expect($header[0]['type'])->toBe('group')
            ->and($header[0]['bulk'])->toBeTrue()
            ->and($header[1]['type'])->toBe('group')
            // One non-bulk child is enough to keep the group always visible.
            ->and($header[1]['bulk'])->toBeFalse()
            ->and(array_column($header[1]['actions'], 'name'))->toBe(['plainOne', 'bulkTwo']);
    });

    it('drops invisible header actions before they reach the client', function (): void {
        UserResource::$headerActions = fn (): array => [
            Action::make('visibleOne')->action(fn () => null),
            Action::make('hiddenOne')->action(fn () => null)->hidden(),
        ];

        expect(array_column(tableActionsConfig()['schema']['actions']['header'], 'name'))
            ->toBe(['visibleOne']);
    });
});

describe('record actions position', function (): void {
    it('defaults to the trailing cell', function (): void {
        // A columns-layout table resolves to `AfterColumns`; the island puts
        // both trailing positions in the same slot.
        expect(tableActionsConfig()['schema']['recordActions'])->toMatchArray([
            'position' => 'AfterColumns',
            'alignmentClass' => null,
            'columnLabel' => null,
        ]);
    });

    it('carries BeforeColumns and BeforeCells through', function (): void {
        UserResource::$recordActionsPosition = RecordActionsPosition::BeforeColumns;
        expect(tableActionsConfig()['schema']['recordActions']['position'])->toBe('BeforeColumns');

        UserResource::$recordActionsPosition = RecordActionsPosition::BeforeCells;
        expect(tableActionsConfig()['schema']['recordActions']['position'])->toBe('BeforeCells');
    });

    it('maps the alignment to stock\'s own class, with none for the end default', function (): void {
        UserResource::$recordActionsAlignment = Alignment::Center->value;
        expect(tableActionsConfig()['schema']['recordActions']['alignmentClass'])->toBe('fi-align-center');

        UserResource::$recordActionsAlignment = Alignment::Start->value;
        expect(tableActionsConfig()['schema']['recordActions']['alignmentClass'])->toBe('fi-align-start');

        // End/Right is the default look — stock emits NO alignment class there.
        UserResource::$recordActionsAlignment = Alignment::End->value;
        expect(tableActionsConfig()['schema']['recordActions']['alignmentClass'])->toBeNull();
    });

    it('carries the actions column label and the pluralized default heading', function (): void {
        UserResource::$recordActionsColumnLabel = 'Do things';

        $schema = tableActionsConfig()['schema'];

        expect($schema['recordActions']['columnLabel'])->toBe('Do things')
            // The empty header cell's accessible name, pluralized on the
            // number of record actions exactly as stock's trans_choice is.
            ->and($schema['strings']['recordActionsHeading'])->not->toBeEmpty();
    });
});

describe('action trigger variants', function (): void {
    it('serializes a link trigger with the finished Filament class string', function (): void {
        User::factory()->create(['name' => 'Trigger Link', 'is_admin' => false]);

        $edit = tableActionsRecord('Trigger Link')['actions']['edit'];

        expect($edit['view'])->toBe('link')
            ->and($edit['tag'])->toBe('a')
            ->and($edit['class'])->toStartWith('fi-ac-link-action fi-link fi-size-sm')
            ->and($edit['labelHidden'])->toBeFalse()
            ->and($edit['iconPosition'])->toBe('before');
    });

    it('honors modifyUngroupedRecordActionsUsing (the docs\' iconButton example)', function (): void {
        UserResource::$modifyUngroupedRecordActions = fn (Action $action) => $action->iconButton();

        User::factory()->create(['name' => 'Trigger Icon', 'is_admin' => false]);

        $edit = tableActionsRecord('Trigger Icon')['actions']['edit'];

        expect($edit['view'])->toBe('iconButton')
            ->and($edit['class'])->toStartWith('fi-ac-icon-btn-action fi-icon-btn fi-size-sm')
            // The label survives as the accessible name / title only.
            ->and($edit['label'])->toBe('Edit');
    });

    it('serializes button, badge, tooltip, size, hiddenLabel and counter badge', function (): void {
        UserResource::$modifyUngroupedRecordActions = fn (Action $action) => $action
            ->button()
            ->size('lg')
            ->tooltip('Change this user')
            ->hiddenLabel()
            ->badge('3')
            ->badgeColor('danger');

        User::factory()->create(['name' => 'Trigger Button', 'is_admin' => false]);

        $edit = tableActionsRecord('Trigger Button')['actions']['edit'];

        expect($edit['view'])->toBe('button')
            ->and($edit['class'])->toContain('fi-btn')->toContain('fi-size-lg')
            ->and($edit['tooltip'])->toBe('Change this user')
            ->and($edit['labelHidden'])->toBeTrue()
            ->and($edit['badge'])->toBe('3')
            ->and($edit['badgeClass'])->toContain('fi-badge')->toContain('fi-color-danger');
    });

    it('serializes a disabled action as a button, never an anchor', function (): void {
        UserResource::$modifyUngroupedRecordActions = fn (Action $action) => $action->disabled();

        User::factory()->create(['name' => 'Trigger Disabled', 'is_admin' => false]);

        $edit = tableActionsRecord('Trigger Disabled')['actions']['edit'];

        expect($edit['disabled'])->toBeTrue()
            ->and($edit['tag'])->toBe('button')
            ->and($edit['url'])->toBeNull()
            ->and($edit['class'])->toContain('fi-disabled');
    });

    it('carries an action\'s extraAttributes as a map, never as html', function (): void {
        UserResource::$modifyUngroupedRecordActions = fn (Action $action) => $action
            ->extraAttributes(['data-test' => 'edit-action']);

        User::factory()->create(['name' => 'Trigger Attrs', 'is_admin' => false]);

        expect(tableActionsRecord('Trigger Attrs')['actions']['edit']['extraAttributes'])
            ->toBe(['data-test' => 'edit-action']);
    });

    it('serializes a grouped bulk action as a dropdown item, not a button', function (): void {
        // Only the GROUP can tell its children they render as dropdown items;
        // reading the flat action list would leave them on the standalone view.
        $delete = tableActionsConfig()['schema']['actions']['bulk'][0]['actions'][0];

        expect($delete['view'])->toBe('grouped')
            ->and($delete['class'])->toStartWith('fi-ac-grouped-action fi-dropdown-list-item')
            // DeleteBulkAction is danger-colored, so the ItemComponent TEXT
            // shades follow (no background — that is the button component's).
            ->and($delete['class'])->toContain('fi-color fi-color-danger')
            ->and($delete['class'])->not->toContain('fi-bg-color-')
            ->and($delete['label'])->toBe('Delete selected');
    });

    it('renders an uncolored dropdown item with no color classes at all', function (): void {
        UserResource::$headerActions = fn (): array => [
            ActionGroup::make([
                Action::make('plainGrouped')->action(fn () => null),
            ]),
        ];

        // ItemComponent has a gray default (HasDefaultGrayColor) — same as stock.
        expect(tableActionsConfig()['schema']['actions']['header'][0]['actions'][0]['class'])
            ->toBe('fi-ac-grouped-action fi-dropdown-list-item');
    });
});

describe('record selection', function (): void {
    it('reports Filament\'s own selection answer, not "are there bulk actions"', function (): void {
        // The fixture has a DeleteBulkAction, so selection is on by default.
        expect(tableActionsConfig()['schema']['selection']['enabled'])->toBeTrue();

        UserResource::$selectable = false;

        expect(tableActionsConfig()['schema']['selection']['enabled'])->toBeFalse();
    });

    it('carries every selection option through', function (): void {
        UserResource::$maxSelectableRecords = 4;
        UserResource::$selectCurrentPageOnly = true;
        UserResource::$selectGroupsOnly = true;
        UserResource::$disabledSelection = true;
        UserResource::$checksIfRecordIsSelectable = true;

        expect(tableActionsConfig()['schema']['selection'])->toMatchArray([
            'enabled' => true,
            'disabled' => true,
            'maxRecords' => 4,
            'currentPageOnly' => true,
            'groupsOnly' => true,
            'checksIfRecordIsSelectable' => true,
        ]);
    });

    it('omits the per-record selectable flag unless the table checks for it', function (): void {
        User::factory()->create(['name' => 'Selectable Off', 'status' => 'archived']);

        expect(tableActionsRecord('Selectable Off'))->not->toHaveKey('selectable');

        UserResource::$checksIfRecordIsSelectable = true;

        expect(tableActionsRecord('Selectable Off')['selectable'])->toBeFalse();
    });

    it('evaluates checkIfRecordIsSelectableUsing per record', function (): void {
        UserResource::$checksIfRecordIsSelectable = true;

        User::factory()->create(['name' => 'Selectable Yes', 'status' => 'active']);
        User::factory()->create(['name' => 'Selectable No', 'status' => 'pending']);

        expect(tableActionsRecord('Selectable Yes')['selectable'])->toBeTrue()
            ->and(tableActionsRecord('Selectable No')['selectable'])->toBeFalse();
    });

    it('ships the selectable total, narrowed by the selectability check', function (): void {
        User::factory()->count(3)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'pending']);

        // Acting user + 5 = 6 records; all of them selectable by default.
        $total = getJson('/admin/villament/users/data')->assertOk()->json('meta.allSelectableCount');

        expect($total)->toBe(6);

        UserResource::$checksIfRecordIsSelectable = true;

        // Only the active ones now: the acting user + 3.
        expect(getJson('/admin/villament/users/data')->assertOk()->json('meta.allSelectableCount'))
            ->toBe(4);
    });

    it('reports no selectable total when selection is off', function (): void {
        UserResource::$selectable = false;

        expect(getJson('/admin/villament/users/data')->assertOk()->json('meta.allSelectableCount'))
            ->toBeNull();
    });
});
