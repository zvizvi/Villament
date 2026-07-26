<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\Users;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(isIndividual: true)
                    ->formatStateUsing(function (string $state): string {
                        static::$formatStateCalls++;

                        return mb_strtoupper($state);
                    })
                    ->url(fn (User $record): string => static::getUrl('edit', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                IconColumn::make('is_admin')
                    ->boolean(),
                ImageColumn::make('avatar_url')
                    ->circular(),
                ViewColumn::make('email')
                    ->view('fixtures.columns.user-email'),
                TextColumn::make('logins_count')
                    ->summarize([
                        Sum::make(),
                        Average::make(),
                    ]),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
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
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
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
