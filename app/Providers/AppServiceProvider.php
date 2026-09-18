<?php

namespace App\Providers;

use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request/job. Everything workspace-scoped reads from here.
        $this->app->scoped(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Workspaces pay, not users: one person may belong to several workspaces and
        // only one of them may be subscribed.
        Cashier::useCustomerModel(\App\Models\Workspace::class);
        Cashier::calculateTaxes();

        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
