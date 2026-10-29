<?php

namespace Wyxos\LaravelAuthentication;

use Illuminate\Support\ServiceProvider;
use Wyxos\LaravelAuthentication\Commands\InstallAuthentication;
use Wyxos\LaravelAuthentication\Commands\PruneAuthentication;

class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wyxos-authentication.php', 'wyxos-authentication');
        $this->app->singleton(AuthenticationMonitor::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'wyxos-authentication');
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/wyxos-authentication.php' => config_path('wyxos-authentication.php')], 'wyxos-authentication-config');
            $this->commands([PruneAuthentication::class, InstallAuthentication::class]);
        }
    }
}
