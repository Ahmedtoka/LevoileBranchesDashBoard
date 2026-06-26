<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tiny bilingual helper: dt('عربي', 'English') → string by current locale.
        if (! function_exists('dt')) {
            function dt(string $ar, string $en): string
            {
                return app()->getLocale() === 'en' ? $en : $ar;
            }
        }
    }

    public function boot(): void
    {
        // Avoid "specified key was too long" on older MySQL.
        Schema::defaultStringLength(191);
    }
}
