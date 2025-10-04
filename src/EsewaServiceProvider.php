<?php

namespace AjayMahato\Esewa;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class EsewaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/esewa.php', 'esewa');

        $this->app->singleton(EsewaClient::class, fn () => new EsewaClient(config('esewa')));
        $this->app->singleton(PaymentManager::class, fn () => new PaymentManager(app(EsewaClient::class), config('esewa')));

        // Facade proxy so Esewa::pay() and client helpers live together
        $this->app->singleton('ajaymahato.esewa.proxy', function () {
            $client = app(EsewaClient::class);
            $mgr    = app(PaymentManager::class);

            return new class($client, $mgr) {
                public function __construct(
                    public \AjayMahato\Esewa\EsewaClient $client,
                    public \AjayMahato\Esewa\PaymentManager $mgr
                ) {}

                public function __call($name, $arguments)
                {
                    if (method_exists($this->mgr, $name)) {
                        return $this->mgr->{$name}(...$arguments);
                    }
                    return $this->client->{$name}(...$arguments);
                }
            };
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/esewa.php' => config_path('esewa.php')], 'esewa-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'esewa');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/esewa.php');

        Blade::componentNamespace('AjayMahato\\Esewa\\View\\Components', 'esewa');
    }
}
