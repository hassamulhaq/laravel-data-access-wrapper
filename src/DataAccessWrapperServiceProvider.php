<?php

namespace Hassam\DataAccessWrapper;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DataAccessWrapperServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('data-access-wrapper')
            ->hasConfigFile()
            ->hasMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('data-access-wrapper', function ($app) {
            return new DataAccessWrapper(
                config('data-access-wrapper', [])
            );
        });
    }
}
