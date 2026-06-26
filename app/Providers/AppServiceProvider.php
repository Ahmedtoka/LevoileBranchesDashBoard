<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // dt() and t() helpers are autoloaded from app/helpers.php (composer "files").
    }

    public function boot(): void
    {
        // Avoid "specified key was too long" on older MySQL.
        Schema::defaultStringLength(191);
    }
}
