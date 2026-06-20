<?php

namespace Hassam\DataAccessWrapper;

use Illuminate\Support\ServiceProvider;

class DataAccessWrapperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cache_versions.php',
            'cache_versions'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cache_versions.php' => config_path('cache_versions.php'),
            ], 'cache-versions-config');
        }
    }
}
