<?php

declare(strict_types=1);

/*
 * One-time preparation for the browser suite: a fresh SQLite file with the
 * fixture tables and one user, and Filament's (and villament's) assets
 * published into the Testbench public directory.
 */

use Illuminate\Contracts\Console\Kernel;
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
]);

fwrite(STDOUT, "ready\n");
