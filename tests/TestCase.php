<?php

namespace AjayMahato\Esewa\Tests;

use AjayMahato\Esewa\EsewaClient;
use AjayMahato\Esewa\EsewaServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EsewaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('app.url', 'http://localhost');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('esewa.mode', 'uat');
        $config->set('esewa.product_code', 'EPAYTEST');
        $config->set('esewa.secret_key', EsewaClient::UAT_SECRET_KEY);
        $config->set('esewa.redirect.success', null);
        $config->set('esewa.redirect.failure', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * A stand-in application model, so the payable morph can be exercised
     * without the package shipping one.
     */
    protected function createOrdersTable(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('orders', function ($table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Model::setConnectionResolver($this->app['db']);
    }
}
