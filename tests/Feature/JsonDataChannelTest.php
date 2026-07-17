<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\Pages\VueListUsers;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create(['name' => 'Acting User', 'status' => 'active']));
});

describe('#[Json] villamentData channel', function (): void {
    it('returns the exact same payload as the GET endpoint for the same query', function (): void {
        User::factory()->count(7)->create(['status' => 'active']);
        User::factory()->count(3)->create(['status' => 'archived']);

        $query = 'sort=name:asc&filters[status][value]=active&perPage=5&page=2';

        $endpoint = getJson('/admin/villament/users/data?'.$query)->assertOk()->json();

        $json = livewire(VueListUsers::class)->instance()->villamentData($query);

        // Same serializers, same validator, same shape — the channels cannot
        // drift. (json_encode normalizes objects/arrays for the comparison.)
        expect(json_decode(json_encode($json), true))->toEqual($endpoint);
    });

    it('applies sort, search and pagination state from the query string', function (): void {
        User::factory()->create(['name' => 'Aaa Target']);
        User::factory()->create(['name' => 'Zzz Target']);
        User::factory()->count(8)->create();

        $payload = livewire(VueListUsers::class)->instance()->villamentData('sort=name:desc&search=Target&perPage=10');

        expect($payload['meta']['total'])->toBe(2)
            // The fixture's name column upper-cases its state — sort DESC put Zzz first.
            ->and((string) $payload['records'][0]['cells']['name']['formatted'])->toContain('ZZZ TARGET');
    });

    it('runs through the Livewire pipe and returns the payload (render skipped)', function (): void {
        User::factory()->count(2)->create();

        livewire(VueListUsers::class)
            ->call('villamentData', 'perPage=10')
            // The #[Json] pipe returns the payload directly (render skipped);
            // total = 2 seeded + the acting user.
            ->assertReturned(fn (?array $payload): bool => ($payload['meta']['total'] ?? null) === 3
                && is_array($payload['records'])
                && isset($payload['schemaVersion']));
    });

    it('rejects unauthorized users', function (): void {
        $page = livewire(VueListUsers::class)->instance();

        actingAs(User::factory()->blocked()->create());

        expect(fn () => $page->villamentData(''))
            ->toThrow(HttpException::class);
    });

    it('drops invalid state instead of applying it', function (): void {
        User::factory()->count(3)->create();

        // Unknown sort column + bogus perPage: the validator rejects with 422
        // exactly like the endpoint does.
        expect(fn () => livewire(VueListUsers::class)->instance()->villamentData('sort=evil_column:asc'))
            ->toThrow(ValidationException::class);
    });
});
