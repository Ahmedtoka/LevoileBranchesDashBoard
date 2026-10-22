<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Policies\ObservationCatalogPolicy;
use App\Policies\ObservationPolicy;
use App\Policies\ObservationVisitPolicy;
use Illuminate\Support\Facades\Gate;
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

        Gate::policy(ObservationVisit::class, ObservationVisitPolicy::class);
        Gate::policy(Observation::class, ObservationPolicy::class);

        foreach ([ObservationCategory::class, ObservationCatalogItem::class, Area::class, RiskLevel::class] as $model) {
            Gate::policy($model, ObservationCatalogPolicy::class);
        }
    }
}
