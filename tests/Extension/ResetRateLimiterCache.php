<?php

declare(strict_types=1);

namespace App\Tests\Extension;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Clears the rate limiter's persistent counters before every test.
 *
 * POST /api/login is throttled per account and per IP, and those counters live in
 * the filesystem cache pool, which is meant to survive between requests and kernel
 * reboots. A suite that signs in from 127.0.0.1 dozens of times therefore spends a
 * single shared IP budget: tests passed or failed depending on the order PHPUnit
 * ran them in (57 failures on CI against a green local run).
 *
 * Production behaviour is untouched — the real limits stay configured — while each
 * test starts from an empty bucket. Tests that assert throttling still observe
 * their own requests accumulating, because the clear happens once per test.
 */
final class ResetRateLimiterCache implements Extension
{
    /** Pools live under APP_SHARE_DIR so they survive a cache:clear (the limiter
     *  must not forget an attacker's attempts when the cache is warmed). */
    private const DEFAULT_POOL_DIRECTORY = 'var/share/test/pools';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $poolDirectory = $parameters->has('poolDirectory')
            ? $parameters->get('poolDirectory')
            : self::DEFAULT_POOL_DIRECTORY;

        $facade->registerSubscriber(new class($poolDirectory) implements PreparedSubscriber {
            public function __construct(private readonly string $poolDirectory)
            {
            }

            public function notify(Prepared $event): void
            {
                self::remove($this->poolDirectory);
            }

            private static function remove(string $path): void
            {
                if (!is_dir($path)) {
                    return;
                }

                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }

                rmdir($path);
            }
        });
    }
}
