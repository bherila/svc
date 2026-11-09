<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Application;

/**
 * Boots the application with service packages switched on, or off.
 *
 * The package operations are registered, and their routes bound, only while
 * `svc.service_packages.enabled` is on when the application boots (API-6), so
 * setting the flag inside a test is too late. A test that needs the agent
 * surface, or compares the registry with the checked OpenAPI document (which
 * always documents them), boots with it on. A test of the switched-off surface
 * overrides {@see self::servicePackagesAtBoot()}, so a developer's own .env
 * cannot turn it on.
 */
trait BootsServicePackages
{
    protected static function servicePackagesAtBoot(): bool
    {
        return true;
    }

    public function createApplication(): Application
    {
        $name = 'SVC_SERVICE_PACKAGES_ENABLED';
        $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        $value = static::servicePackagesAtBoot() ? 'true' : 'false';
        // The environment, not the configuration: it is read while the
        // application loads its configuration, before any route is bound.
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
        try {
            return parent::createApplication();
        } finally {
            [$env, $server, $process] = $saved;
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            putenv($process === false ? $name : "{$name}={$process}");
        }
    }
}
