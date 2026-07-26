<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\HookedUsers\Pages\HookedListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

/**
 * @return array<string, mixed>
 */
function hookedColumn(string $key): array
{
    $columns = livewire(HookedListUsers::class)
        ->instance()
        ->getVillamentTableConfig()['schema']['columns'];

    return collect($columns)->firstWhere('key', $key);
}

describe('html column labels', function (): void {
    it('ships an Htmlable label as markup for the header to render', function (): void {
        expect(hookedColumn('email')['labelHtml'])
            ->toBe('<span class="fcf-header">Email<span class="fcf-trigger"></span></span>');
    });

    it('keeps label itself the plain accessible text, never the markup', function (): void {
        expect(hookedColumn('email')['label'])->toBe('Email');
    });

    it('leaves a plain label alone, with no labelHtml at all', function (): void {
        expect(hookedColumn('status'))
            ->label->toBe('Status')
            ->labelHtml->toBeNull();
    });

    it('carries the decorating plugin extraHeaderAttributes through', function (): void {
        expect(hookedColumn('email')['extraHeaderAttributes'])->toBe(['class' => 'fcf-th']);
    });

    it('does not reload the island when a plugin decorates only at render time', function (): void {
        $component = livewire(HookedListUsers::class);

        // Rendering is what fires the decorating hook; the data endpoint never
        // renders, so its schema legitimately differs here.
        $component->html();
        $page = $component->instance()->getVillamentTableConfig();

        expect(collect($page['schema']['columns'])->firstWhere('key', 'name')['labelHtml'])
            ->toContain('class="decorated"');

        $endpoint = Pest\Laravel\getJson('/admin/villament/hooked-users/data')->assertOk();

        // Cosmetic drift must not reach the hash, or the island would reload
        // on every single fetch — reload, fetch, reload.
        expect($endpoint->json('schemaVersion'))->toBe($page['schemaVersion']);
    });
});
