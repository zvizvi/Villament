<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Zvizvi\Villament\Support\SchemaSerializer;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));

    $this->active = User::factory()->create(['name' => 'Aaron Active', 'status' => 'active']);
    $this->pending = User::factory()->create(['name' => 'Penny Pending', 'status' => 'pending']);
});

it('sees and does not see records on a Villament page, following the page state', function (): void {
    livewire(VueListUsers::class)
        ->assertCanSeeTableRecords([$this->active, $this->pending])
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->active]);
});

it('checks the order on a Villament page', function (): void {
    livewire(VueListUsers::class)
        ->set('tableSort', 'name:asc')
        ->assertCanSeeTableRecords([$this->active, $this->pending], inOrder: true);

    expect(fn () => livewire(VueListUsers::class)
        ->set('tableSort', 'name:asc')
        ->assertCanSeeTableRecords([$this->pending, $this->active], inOrder: true))
        ->toThrow(AssertionFailedError::class);
});

it('fails on a Villament page when a record is not shown', function (): void {
    expect(fn () => livewire(VueListUsers::class)
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$this->active]))
        ->toThrow(AssertionFailedError::class);
});

it('keeps the HTML-based behaviour on a stock page', function (): void {
    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$this->active, $this->pending])
        ->assertSeeHtml('.table.records.'.$this->active->getKey());
});

it('renders a column on a Villament page exactly when the rows carry its cell', function (): void {
    livewire(VueListUsers::class)
        ->assertCanRenderTableColumn('name')
        // Toggled hidden by default, so no row carries it.
        ->assertCanNotRenderTableColumn('created_at');
});

it('finds what the cells say, formatted and escaped as a stock assertSee would', function (): void {
    livewire(VueListUsers::class)
        ->assertSeeInTable('AARON ACTIVE')
        ->set('activeTab', 'pending')
        ->assertSeeInTable('PENNY PENDING')
        ->assertDontSeeInTable('AARON ACTIVE');
});

it('hands back the text it searches', function (): void {
    expect(livewire(VueListUsers::class)->getTableText())->toContain('AARON ACTIVE');
});

it("carries the table's extraAttributes into the island schema", function (): void {
    UserResource::$tableExtraAttributes = ['class' => 'fi-ta-density-dense'];

    try {
        $table = livewire(VueListUsers::class)->instance()->getTable();

        expect((new SchemaSerializer)->serialize($table)['extraAttributes'])->toBe(['class' => 'fi-ta-density-dense']);
    } finally {
        UserResource::$tableExtraAttributes = [];
    }
});

it('draws a lineClamp() column with its stock markup, the clamp on the item', function (): void {
    UserResource::$nameLineClamp = 2;

    try {
        $cell = getJson('/admin/villament/users/data')->assertOk()->json('records.0.cells.name');

        expect($cell['type'])->toBe('html')
            ->and($cell['bare'])->toBeTrue()
            ->and($cell['html'])->toContain('fi-ta-text-item')->toContain('--line-clamp: 2');
    } finally {
        UserResource::$nameLineClamp = null;
    }
});

it('keeps a plain text column as a structured text cell', function (): void {
    expect(getJson('/admin/villament/users/data')->assertOk()->json('records.0.cells.name.type'))->toBe('text');
});
