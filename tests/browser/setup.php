<?php

declare(strict_types=1);

/*
 * One-time preparation for the browser suite: a fresh SQLite file with the
 * fixture tables and one user, and Filament's (and villament's) assets
 * published into the Testbench public directory.
 */

use Illuminate\Contracts\Console\Kernel;
use Zvizvi\Villament\Tests\Fixtures\Models\Team;
use Zvizvi\Villament\Tests\Fixtures\Models\User;

$database = getenv('VILLAMENT_BROWSER_DB') ?: sys_get_temp_dir().'/villament-browser.sqlite';

@unlink($database);
touch($database);

$app = require __DIR__.'/app.php';

$console = $app->make(Kernel::class);

$console->call('migrate', ['--path' => realpath(__DIR__.'/../Fixtures/database/migrations'), '--realpath' => true, '--force' => true]);
$console->call('filament:assets');

User::query()->create([
    'name' => 'Browser',
    'email' => 'browser@example.test',
    'password' => bcrypt('password'),
    // The fixture UserPolicy lets only an admin delete: the relation
    // manager scenarios delete a member through the actions bridge.
    'is_admin' => true,
]);

// The relation manager scenarios' owner record (`TeamRelationsResource`).
$team = Team::query()->create(['name' => 'Relations', 'slug' => 'relations']);

foreach (['Ada', 'Bea', 'Cai', 'Dov', 'Eli', 'Fay', 'Gil'] as $name) {
    User::query()->create([
        'name' => $name,
        'email' => strtolower($name).'@example.test',
        'password' => 'x',
        'team_id' => $team->getKey(),
    ]);
}

foreach (['delta', 'alpha', 'charlie'] as $body) {
    $team->notes()->create(['kind' => 'client', 'body' => $body]);
}

// The tab scenarios (`TabbedUserResource`): the `pending` tab draws a column
// the `all` tab does not.
foreach (['Pia', 'Quin', 'Rex'] as $name) {
    User::query()->create([
        'name' => $name,
        'email' => strtolower($name).'@example.test',
        'password' => 'x',
        'status' => 'pending',
    ]);
}

fwrite(STDOUT, "ready\n");
