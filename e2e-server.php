<?php

declare(strict_types=1);

/**
 * Front controller for the end-to-end suite (Playwright). NOT for production.
 *
 * Why this exists: `public/index.php` boots through symfony/runtime, which reads
 * APP_ENV from $_SERVER and then consults the generated `.env.local.php` dump. In
 * this workspace that dump pins APP_ENV=test, and `php -S` does not expose the
 * process environment to $_SERVER the way the CLI does — so the "e2e" server
 * silently booted the *test* environment against the wrong database, and every
 * login returned 401.
 *
 * Loading `.env` + `.env.e2e` explicitly and constructing the kernel with 'e2e'
 * makes the environment deterministic. `.env.local.php` is deliberately bypassed
 * (it is a developer-owned generated file we must not modify).
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require __DIR__.'/vendor/autoload.php';

// loadEnv() reads .env, .env.local, .env.e2e and .env.e2e.local — but never the
// compiled .env.local.php dump.
// Load the two files explicitly, in order, with override enabled, and never let
// the files decide the environment: loadEnv() re-reads APP_ENV from what it just
// loaded, and this workspace's .env/.env.dev pin APP_ENV=dev with the dev
// DATABASE_URL — which is exactly how the e2e server kept landing on the wrong
// database.
$dotenv = new Dotenv();
foreach (['.env', '.env.e2e'] as $file) {
    $contents = file_get_contents(__DIR__.'/'.$file);
    $dotenv->populate($dotenv->parse(false === $contents ? '' : $contents, $file), true);
}
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'e2e';

$kernel = new Kernel('e2e', true);

// Diagnostic (opt-in with E2E_DIAGNOSTICS=1): report the database this process
// actually talks to. Never enabled in a normal run.
if (getenv('E2E_DIAGNOSTICS') && ($_SERVER['REQUEST_URI'] ?? '') === '/__e2e/db') {
    $kernel->boot();
    $conn = $kernel->getContainer()->get('doctrine')->getConnection();
    header('Content-Type: application/json');
    echo json_encode([
        'environment' => $kernel->getEnvironment(),
        'database' => $conn->getDatabase(),
        'params' => array_diff_key($conn->getParams(), ['password' => true]),
        'server_env' => $_SERVER['APP_ENV'] ?? null,
        'server_db' => $_SERVER['DATABASE_URL'] ?? null,
        'cache_dir' => $kernel->getCacheDir(),
        'tables' => $conn->executeQuery("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchFirstColumn(),
    ], JSON_THROW_ON_ERROR);
    $kernel->shutdown();

    return;
}

$request = Request::createFromGlobals();
// E2E_DEBUG_THROW=1 surfaces the real exception instead of an opaque 500.
$response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, !getenv('E2E_DEBUG_THROW'));
$response->send();
$kernel->terminate($request, $response);
