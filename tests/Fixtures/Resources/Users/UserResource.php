<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Users;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\EditUser;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    /** Test seam: flips the table into `groupsOnly()` mode for the groups-only tests. */
    public static bool $groupsOnly = false;

    /** Test seam: hides the grouping settings selector (`groupingSettingsHidden()`). */
    public static bool $groupingSettingsHidden = false;

    /** Test seams: the two `summaries()` toggles (page row / all-records row). */
    public static bool $hasPageSummary = true;

    public static bool $hasAllTableSummary = true;

    /** Test seams: the table's own search options (`searchDebounce()`, …). */
    public static ?string $searchDebounce = null;

    public static bool $searchOnBlur = false;

    public static ?string $searchPlaceholder = null;

    /** Test seam: `loadingSkeleton()`. */
    public static bool $hasLoadingSkeleton = false;

    /** Test seam: the three `persist*InSession()` toggles, flipped together. */
    public static bool $persistsInSession = false;

    /**
     * Test seam: `inline()` on every column, the way an app applies it — a
     * global `Column::configureUsing()`.
     */
    public static bool $inlineColumns = false;

    /** Test seams: the table's own header block (`heading()`, `description()`). */
    public static ?string $heading = null;

    public static ?string $description = null;

    /**
     * Test seam: `header()` — the custom view that REPLACES the whole header
     * block. Filament evaluates the closure itself.
     *
     * @var ?Closure(): (View|Htmlable|null)
     */
    public static ?Closure $header = null;

    /**
     * Test seam: `recordClasses()`. Accepts a string, a list, or Laravel's
     * conditional `['class' => bool]` map — the fixture passes whatever the
     * test sets straight through.
     *
     * @var array<string|int, bool|string>|string|Closure|null
     */
    public static array|string|Closure|null $recordClasses = null;

    /** Test seams: the empty state's own options. */
    public static ?string $emptyStateHeading = null;

    public static ?string $emptyStateDescription = null;

    /**
     * Test seam: `emptyState()` — the custom view that REPLACES the whole
     * empty-state block.
     *
     * @var ?Closure(): (View|Htmlable|null)
     */
    public static ?Closure $emptyState = null;

    /**
     * Test seam: `emptyStateActions()`.
     *
     * @var ?Closure(): array<mixed>
     */
    public static ?Closure $emptyStateActions = null;

    /**
     * Test seam: `headerActions()`. A closure so a test can mix plain and bulk
     * actions (both are legal there) without the fixture importing them.
     *
     * @var ?Closure(): array<mixed>
     */
    public static ?Closure $headerActions = null;

    /** Test seams: `recordActions(position:)` + its alignment/label options. */
    public static mixed $recordActionsPosition = null;

    public static ?string $recordActionsAlignment = null;

    public static ?string $recordActionsColumnLabel = null;

    /**
     * Test seam: `modifyUngroupedRecordActionsUsing()` — Filament's global hook
     * for restyling every ungrouped record action (the docs' example is
     * `->iconButton()`).
     *
     * @var ?Closure(Action): mixed
     */
    public static ?Closure $modifyUngroupedRecordActions = null;

    /** Test seams: the record-selection options. */
    public static ?bool $selectable = null;

    public static ?int $maxSelectableRecords = null;

    public static bool $selectCurrentPageOnly = false;

    public static bool $selectGroupsOnly = false;

    public static bool $disabledSelection = false;

    /**
     * Test seam: `checkIfRecordIsSelectableUsing()`. When true, only ACTIVE
     * users are selectable.
     */
    public static bool $checksIfRecordIsSelectable = false;

    /**
     * Test seam: the table's `defaultGroup()`. Set to an id that is also in
     * `groups()` for a SELECTABLE default (stock's mount hook seeds
     * `tableGrouping` with it); set to an id that is not, for a forced one.
     */
    public static ?string $defaultGroup = null;

    /**
     * Test seam: customizes the grouping dropdown trigger
     * (`groupRecordsTriggerAction()`), e.g. `fn (Action $action) => $action->button()`.
     *
     * @var ?Closure(Action): Action
     */
    public static ?Closure $groupRecordsTriggerAction = null;

    /**
     * Test probe: counts every `formatStateUsing` evaluation, so tests can
     * assert that per-record column closures run (or, in groupsOnly mode,
     * never run — stock renders no cells there).
     */
    public static int $formatStateCalls = 0;

    /**
     * Test seam: a badge and a grouped record action whose closures demand a
     * REAL record (`fn (User $record)`), as app code routinely writes them.
     * Evaluated without one, they throw a TypeError and take the page down.
     */
    public static bool $recordTypedClosures = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_banned', false);
    }

    /** The uppercased first letter of a name — the `initial` group's key. */
    public static function initial(User $record): string
    {
        return mb_strtoupper(mb_substr((string) $record->name, 0, 1));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('email')->email()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        if (static::$inlineColumns) {
            Column::configureUsing(fn (Column $column): Column => $column->inline());
        }

        return $table
            ->columns([
                ...(static::$recordTypedClosures ? [
                    TextColumn::make('record_typed_badge')
                        ->state(fn (User $record): string => $record->status)
                        ->badge(fn (User $record): bool => $record->status === 'active'),
                ] : []),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(isIndividual: true)
                    ->formatStateUsing(function (string $state): string {
                        static::$formatStateCalls++;

                        return mb_strtoupper($state);
                    })
                    ->url(fn (User $record): string => static::getUrl('edit', ['record' => $record]))
                    // Cell + header tooltips, and the row-number closure that
                    // proves `$rowLoop` reaches column closures at all.
                    ->tooltip(fn (User $record, \stdClass $rowLoop): string => "Row {$rowLoop->iteration} of {$rowLoop->count}: {$record->name}")
                    ->headerTooltip('The user\'s full name')
                    ->description('Primary contact', position: 'above')
                    ->extraAttributes(['data-name-cell' => 'yes'])
                    ->extraCellAttributes(['data-td' => 'name'])
                    ->extraHeaderAttributes(['data-th' => 'name']),
                // Carries every header/cell modifier at once, so the serialized
                // class list can be asserted against stock's own `@class` set.
                TextColumn::make('status')
                    ->wrapHeader()
                    ->grow()
                    ->verticalAlignment(VerticalAlignment::End)
                    ->hiddenFrom('md')
                    ->visibleFrom('xl')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                // A ColumnGroup spanning two adjacent columns. The `&` in
                // the label is deliberate: Filament keys its column-manager
                // group entries (and `getColumnGroups()`) by `e($label)`, so
                // this proves the island matches on the ESCAPED key while
                // rendering the raw label exactly once.
                ColumnGroup::make('Profile & Access', [
                    IconColumn::make('is_admin')
                        ->boolean()
                        ->placeholder('No role')
                        ->emptyTooltip('This user has no role set'),
                    ImageColumn::make('avatar_url')
                        ->circular()
                        ->placeholder('No avatar')
                        ->tooltip('Profile picture'),
                ])
                    ->wrapHeader()
                    ->alignment(Alignment::Center),
                ViewColumn::make('email')
                    ->view('fixtures.columns.user-email'),
                TextColumn::make('logins_count')
                    ->summarize([
                        Sum::make(),
                        Average::make(),
                    ]),
                // An array state under the list modifiers: three values, only
                // two kept, the third reported as "+1 more".
                TextColumn::make('aliases')
                    ->getStateUsing(fn (): array => ['Alpha', 'Beta', 'Gamma'])
                    ->listWithLineBreaks()
                    ->limitList(2),
                // The same list, expandable: nothing is dropped server-side,
                // the overflow is hidden until the reader asks for it.
                TextColumn::make('nicknames')
                    ->getStateUsing(fn (): array => ['Alpha', 'Beta', 'Gamma'])
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
                // A cell whose click is disabled: stock renders a plain div
                // even though the record has a url.
                TextColumn::make('team.name')
                    ->label('Team')
                    ->disabledClick()
                    ->placeholder('No team'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    // An explicit width suppresses `fi-growable`, even with
                    // `grow()` set — stock's `blank($width) && canGrow()`.
                    ->grow()
                    ->width('12rem')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            // Search + persistence options, driven by the test seams above.
            ->searchDebounce(static::$searchDebounce ?? '500ms')
            ->searchOnBlur(static::$searchOnBlur)
            ->searchPlaceholder(static::$searchPlaceholder)
            ->loadingSkeleton(static::$hasLoadingSkeleton)
            ->persistSortInSession(static::$persistsInSession)
            ->persistSearchInSession(static::$persistsInSession)
            ->persistColumnSearchesInSession(static::$persistsInSession)
            ->groups([
                Group::make('status')
                    ->collapsible()
                    ->getDescriptionFromRecordUsing(fn (User $record): string => "Users with status {$record->status}"),
                Group::make('created_at')
                    ->date(),
                // Relationship grouping (dot-syntax): exercises the Group's
                // eager loading, RelationshipOrderer subquery ordering, and
                // `whereHas` key scoping.
                Group::make('team.name')
                    ->label('Team'),
                // Every query/label closure at once, over a column that does
                // NOT exist ('initial') — so the closures are the ONLY thing
                // that can produce a working key/title/order/scope.
                Group::make('initial')
                    ->label('Initial')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (User $record): string => static::initial($record))
                    ->getTitleFromRecordUsing(fn (User $record): string => 'Starts with '.static::initial($record))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderByRaw('upper(substr(name, 1, 1)) '.($direction === 'desc' ? 'desc' : 'asc')))
                    ->groupQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->groupByRaw('upper(substr(name, 1, 1))'))
                    ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $query->whereRaw('upper(substr(name, 1, 1)) = ?', [$key])),
            ])
            ->summaries(
                pageCondition: fn (): bool => static::$hasPageSummary,
                allTableCondition: fn (): bool => static::$hasAllTableSummary,
            )
            ->defaultGroup(fn (): ?string => static::$defaultGroup)
            ->groupsOnly(fn (): bool => static::$groupsOnly)
            ->groupingSettingsHidden(fn (): bool => static::$groupingSettingsHidden)
            ->groupRecordsTriggerAction(static::$groupRecordsTriggerAction)
            ->striped()
            ->reorderableColumns()
            ->filtersFormColumns(['sm' => 2, 'md' => 3, 'lg' => 4])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'archived' => 'Archived',
                    ]),
                SelectFilter::make('statuses')
                    ->attribute('status')
                    ->multiple()
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'archived' => 'Archived',
                    ]),
                TernaryFilter::make('is_admin'),
                Filter::make('created_from')
                    ->schema([
                        DatePicker::make('created_from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['created_from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['created_from'] ?? null)
                        ? 'Created from '.$data['created_from']
                        : null),
            ])
            ->recordClasses(static::$recordClasses)
            ->emptyState(static::$emptyState)
            ->emptyStateHeading(static::$emptyStateHeading)
            ->emptyStateDescription(static::$emptyStateDescription)
            ->emptyStateActions(static::$emptyStateActions === null ? [] : (static::$emptyStateActions)())
            ->header(static::$header)
            ->heading(static::$heading)
            ->description(static::$description)
            ->headerActions(static::$headerActions === null ? [] : (static::$headerActions)())
            // Selection options. `selectable(null)` leaves Filament's own
            // fallback in place ("are any bulk actions visible?").
            ->selectable(static::$selectable)
            ->maxSelectableRecords(static::$maxSelectableRecords)
            ->selectCurrentPageOnly(static::$selectCurrentPageOnly)
            ->selectGroupsOnly(static::$selectGroupsOnly)
            ->disabledSelection(static::$disabledSelection)
            ->checkIfRecordIsSelectableUsing(
                static::$checksIfRecordIsSelectable
                    ? fn (User $record): bool => $record->status === 'active'
                    : null,
            )
            // MUST precede `recordActions()`: Filament applies it as each
            // action is pushed, not at render time.
            ->modifyUngroupedRecordActionsUsing(static::$modifyUngroupedRecordActions)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ...(static::$recordTypedClosures ? [
                    ActionGroup::make([
                        Action::make('archive')
                            ->visible(fn (User $record): bool => $record->status !== 'archived')
                            ->action(fn () => null),
                        Action::make('activate')
                            ->visible(fn (User $record): bool => $record->status !== 'active')
                            ->action(fn () => null),
                    ]),
                ] : []),
            ], position: static::$recordActionsPosition)
            ->recordActionsAlignment(static::$recordActionsAlignment)
            ->recordActionsColumnLabel(static::$recordActionsColumnLabel)
            ->toolbarActions([
                Action::make('announce')
                    ->url('https://example.test/announce')
                    ->openUrlInNewTab(),
                Action::make('markAllPending')
                    ->action(fn () => User::query()->update(['status' => 'pending'])),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'vue' => VueListUsers::route('/vue'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
