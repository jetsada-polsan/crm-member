<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application and refuse to run tests against anything but the in-memory test database.
     *
     * RefreshDatabase runs migrate:fresh, so a cached production config pointing at the live
     * PostgreSQL database would wipe real ERP and CRM data.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->ensureIsolatedTestDatabase($app);

        return $app;
    }

    private function ensureIsolatedTestDatabase(Application $app): void
    {
        $config = $app['config'];
        $defaultConnection = (string) $config->get('database.default');
        $database = (string) $config->get("database.connections.{$defaultConnection}.database");

        $isIsolated = $app->environment('testing')
            && $defaultConnection === 'sqlite'
            && $database === ':memory:';

        if (! $isIsolated) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: expected APP_ENV=testing on sqlite :memory:, got APP_ENV=%s on %s (%s). '
                .'A cached config (bootstrap/cache/config.php) may be overriding phpunit.xml.',
                $app->environment(),
                $defaultConnection,
                $database,
            ));
        }
    }
}
