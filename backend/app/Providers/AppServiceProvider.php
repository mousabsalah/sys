<?php

namespace App\Providers;

use App\Services\Backup\BackupGateway;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BackupGateway::class, DatabaseDumper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
