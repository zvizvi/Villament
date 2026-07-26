<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\Pages\HookedListUsers;

class HookedUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'hooked-users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                // How a header-decorating plugin gets its trigger into the
                // `<th>`: it swaps the label for markup and tags the cell.
                TextColumn::make('email')
                    ->label(new HtmlString('<span class="fcf-header">Email<span class="fcf-trigger"></span></span>'))
                    ->extraHeaderAttributes(['class' => 'fcf-th']),
                // Left plain, so the undecorated path stays covered.
                TextColumn::make('status'),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => HookedListUsers::route('/'),
        ];
    }
}
