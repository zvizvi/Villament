<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers\Pages\CursorListUsers;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\CursorUsers\Pages\StockCursorListUsers;

class CursorUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'cursor-users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('email'),
            ])
            ->defaultSort('name')
            ->paginationMode(PaginationMode::Cursor);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => CursorListUsers::route('/'),
            'stock' => StockCursorListUsers::route('/stock'),
        ];
    }
}
