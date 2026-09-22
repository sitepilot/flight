<?php

namespace App\Providers;

use App\Stacks\GlobalStack;
use App\Support\GlobalConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A singleton so config.yaml is parsed and validated once per run and
        // every service sees the same values.
        $this->app->singleton(GlobalConfig::class);

        $this->app->singleton(GlobalStack::class);
    }
}
