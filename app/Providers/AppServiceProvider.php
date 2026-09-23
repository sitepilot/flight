<?php

namespace App\Providers;

use App\Stacks\GlobalStack;
use App\Stacks\ProjectStack;
use App\Support\GlobalConfig;
use App\Support\ProjectConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GlobalConfig::class);

        $this->app->singleton(GlobalStack::class);

        $this->app->singleton(ProjectConfig::class);

        $this->app->singleton(ProjectStack::class);
    }
}
