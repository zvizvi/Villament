<?php

declare(strict_types=1);

/*
 * Boots the Testbench fixture application (the same panel, resources and
 * pages the Pest suite uses) for a real browser, outside PHPUnit.
 *
 * Shared by `setup.php` (migrate + seed + publish assets, once) and
 * `server.php` (the `php -S` router, once per request).
 */

use Illuminate\Contracts\Foundation\Application;
use Zvizvi\Villament\Tests\TestCase;

require __DIR__.'/../../vendor/autoload.php';

$database = getenv('VILLAMENT_BROWSER_DB') ?: sys_get_temp_dir().'/villament-browser.sqlite';
$storage = getenv('VILLAMENT_BROWSER_STORAGE') ?: sys_get_temp_dir().'/villament-browser-storage';

$testCase = new class('browser') extends TestCase
{
    public static string $database = '';

    public static string $storage = '';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.testing.database', self::$database);
        // Real requests need a session that outlives the request.
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', self::$storage.'/sessions');
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', self::$storage.'/cache');
        $app['config']->set('app.debug', true);
        // phpunit.xml's key, which `php -S` does not read.
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app->useStoragePath(self::$storage);
    }
};

$testCase::$database = $database;
$testCase::$storage = $storage;

foreach (['', '/sessions', '/cache', '/framework/views', '/app'] as $directory) {
    @mkdir($storage.$directory, recursive: true);
}

/** @var Application $app */
$app = $testCase->createApplication();

$app['config']->set('view.compiled', $storage.'/framework/views');

return $app;
