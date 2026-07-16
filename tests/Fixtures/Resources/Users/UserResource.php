<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users;

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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\EditUser;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_banned', false);
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
                    ->formatStateUsing(fn (string $state): string => mb_strtoupper($state))
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
