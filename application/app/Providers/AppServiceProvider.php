<?php

namespace App\Providers;

use App\Session\HashedDatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Session::extend('hashed-database', function ($app) {
            return new HashedDatabaseSessionHandler(
                DB::connection(config('session.connection')),
                config('session.table'), config('session.lifetime'), $app,
            );
        });
        if ($this->app->environment('production') && (! config('session.secure') || config('app.debug'))) {
            throw new \RuntimeException('Production requires secure session cookies and APP_DEBUG=false.');
        }
    }
}
