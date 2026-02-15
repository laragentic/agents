<?php

declare(strict_types=1);

namespace Laragentic\Tests;

use Dotenv\Dotenv;
use Laravel\Ai\AiServiceProvider;
use Laragentic\LaragenticServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->loadPackageDotenv();

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            AiServiceProvider::class,
            LaragenticServiceProvider::class,
        ];
    }

    /**
     * Load .env from the package root so integration tests can
     * access API keys without hard-coding them.
     */
    protected function loadPackageDotenv(): void
    {
        $envPath = dirname(__DIR__);

        if (file_exists($envPath . '/.env')) {
            Dotenv::createImmutable($envPath)->safeLoad();
        }
    }
}
