<?php

namespace AjayMahato\Esewa\Tests;

use AjayMahato\Esewa\EsewaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('database.default', 'testbench');
        config()->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->artisan('migrate', ['--database' => 'testbench'])->run();
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:reset', ['--database' => 'testbench'])->run();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [EsewaServiceProvider::class];
    }
}
