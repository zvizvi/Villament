<?php

declare(strict_types=1);

namespace Zvizvi\Villament\Tests\Fixtures\Pages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Zvizvi\Villament\Forms\ClientRepeater;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\TeamNote;

/**
 * `relationship()` repeaters, a ClientRepeater and its stock twin, each
 * editing the same team's notes of one kind. Existing records are items
 * keyed `record-{id}`; a save creates, updates, deletes and re-sorts rows.
 *
 * @property-read Schema $form
 */
class RelationshipRepeaterPage extends Page
{
    protected static ?string $slug = 'relationship-repeater';

    protected string $view = 'fixtures.relationship-repeater-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public Team $team;

    public bool $saved = false;

    public function mount(): void
    {
        $this->team = Team::query()->firstOrCreate(['slug' => 'notes'], ['name' => 'Notes']);

        if (! TeamNote::query()->where('team_id', $this->team->getKey())->exists()) {
            foreach (['client', 'stock'] as $kind) {
                foreach (['First', 'Second'] as $sort => $body) {
                    $this->team->notes()->create(['kind' => $kind, 'body' => $body, 'sort' => $sort + 1]);
                }
            }
        }

        // As `EditRecord` does: filling with the record's attributes (not
        // `fill()`, which takes the defaults path) loads the relationships.
        $this->form->fill($this->team->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->team)
            ->statePath('data')
            ->components([
                static::notes(ClientRepeater::class, 'clientNotes', 'client'),
                static::notes(Repeater::class, 'stockNotes', 'stock'),
            ]);
    }

    /**
     * @param  class-string<Repeater>  $class
     */
    public static function notes(string $class, string $relationship, string $kind): Repeater
    {
        return $class::make($relationship)
            ->relationship()
            ->orderColumn('sort')
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'kind' => $kind])
            ->cloneable()
            ->reorderableWithButtons()
            ->schema([
                TextInput::make('body')->required(),
            ]);
    }

    public function save(): void
    {
        $this->form->getState();
        $this->form->saveRelationships();

        $this->saved = true;
    }

    /**
     * What the database holds now, per kind, in sort order.
     *
     * @return array<string, list<array{body: string, sort: int|null}>>
     */
    public function storedNotes(): array
    {
        return collect(['client', 'stock'])
            ->mapWithKeys(fn (string $kind): array => [$kind => TeamNote::query()
                ->where('team_id', $this->team->getKey())
                ->where('kind', $kind)
                ->orderBy('sort')
                ->get(['body', 'sort'])
                ->map(fn (TeamNote $note): array => ['body' => $note->body, 'sort' => $note->sort])
                ->all()])
            ->all();
    }
}
