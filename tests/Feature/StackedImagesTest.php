<?php

declare(strict_types=1);

use Zvizvi\Villament\Tests\Fixtures\Models\User;
use Zvizvi\Villament\Tests\Fixtures\Resources\Users\UserResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function (): void {
    UserResource::$stackedImages = true;

    actingAs(User::factory()->admin()->create(['status' => 'active']));
});

afterEach(function (): void {
    UserResource::$stackedImages = false;
});

it('draws stacked images with the column\'s own stock markup, every image of them', function (): void {
    $record = getJson('/admin/villament/users/data')->assertOk()->json('records.0');
    $cell = $record['cells']['team_avatars'];

    expect($cell['type'])->toBe('html')
        ->and($cell['bare'])->toBeTrue()
        ->and($cell['html'])->toContain('fi-ta-image')
        ->toContain('fi-ta-image-ring-1')
        ->toContain("{$record['key']}-a.png")
        ->toContain("{$record['key']}-b.png");
});
