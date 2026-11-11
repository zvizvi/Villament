<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\ListUsers;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
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
