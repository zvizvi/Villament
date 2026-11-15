<?php

declare(strict_types=1);

/*
 * `php -S` router for the browser suite. Static files under the Testbench
 * public directory are served as-is; everything else goes through the
 * fixture application's HTTP kernel.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Zvizvi\Villament\Tests\Fixtures\Models\User;

$public = realpath(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file($public.$path)) {
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2'];
    header('Content-Type: '.($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($public.$path);

    return true;
}

$app = require __DIR__.'/app.php';

// Test-only sign-in, so the suite does not depend on the login form.
Route::middleware([EncryptCookies::class, StartSession::class])->get('/__browser/login', function () {
    Auth::login(User::query()->firstOrFail());

    return redirect('/admin/repeater-form');
});

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
