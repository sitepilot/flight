<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Browser;

class OpenCommand extends ProjectCommand
{
    protected $signature = 'open {service? : The service to open, defaults to the app}';

    protected $description = 'Open the project in your browser';

    public function handle(ProjectStack $stack, Browser $browser): int
    {
        $url = 'https://'.$this->routedService($stack, $this->argument('service'), 'open')->hostnames()[0];

        $browser->open($url);

        $this->step("Opened {$url}");

        return self::SUCCESS;
    }
}
