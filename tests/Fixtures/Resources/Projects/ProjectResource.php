<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\Project;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects\Pages\ListProjects;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Resources\Projects\Pages\ViewProject;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $tenantOwnershipRelationshipName = 'team';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
            ])
            ->defaultSort('name')
            // Reproduces the reported failure: a tenant-scoped getUrl() inside
            // a recordUrl closure, evaluated per row by RecordSerializer.
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'view' => ViewProject::route('/{record}'),
        ];
    }
}
