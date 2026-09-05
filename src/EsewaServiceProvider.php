<?php

namespace AjayMahato\Esewa;

use AjayMahato\Esewa\Console\ReconcileCommand;
use Illuminate\Support\ServiceProvider;

class EsewaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/esewa.php', 'esewa');

        $this->app->singleton(EsewaClient::class, fn ($app) => new EsewaClient($app['config']->get('esewa', [])));

        $this->app->singleton(
            PaymentManager::class,
            fn ($app) => new PaymentManager($app->make(EsewaClient::class), $app['config']->get('esewa', []))
        );

        $this->app->singleton(
            Esewa::class,
            fn ($app) => new Esewa($app->make(EsewaClient::class), $app->make(PaymentManager::class))
        );

        $this->app->alias(Esewa::class, 'esewa');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'esewa');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ((bool) config('esewa.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/esewa.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommand::class]);

            $this->publishes([
                __DIR__.'/../config/esewa.php' => config_path('esewa.php'),
            ], 'esewa-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'esewa-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/esewa'),
            ], 'esewa-views');

            $this->publishes([
                __DIR__.'/../config/esewa.php' => config_path('esewa.php'),
                __DIR__.'/../resources/views' => resource_path('views/vendor/esewa'),
            ], 'esewa');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [EsewaClient::class, PaymentManager::class, Esewa::class, 'esewa'];
    }
}
