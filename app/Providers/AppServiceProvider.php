<?php

namespace App\Providers;

use App\Stacks\GlobalStack;
use App\Stacks\ProjectStack;
use App\Support\GlobalConfig;
use App\Support\ProjectConfig;
use App\Support\Variables;
use Illuminate\Support\ServiceProvider;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GlobalConfig::class);

        $this->app->singleton(GlobalStack::class);

        $this->app->singleton(ProjectConfig::class);

        $this->app->singleton(ProjectStack::class);

        // Remembers where it read a step's variables, for `flight up` to warn.
        $this->app->singleton(Variables::class);

        // The summary that `flight` shows without a command is named "list",
        // which would replace `flight list`.
        $this->app->extend(SummaryCommand::class, fn (SummaryCommand $command) => $command->setName('summary'));
    }
}
