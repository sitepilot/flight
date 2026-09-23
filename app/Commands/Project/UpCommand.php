<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Provisioning\Provisioner;
use App\Stacks\GlobalStack;
use App\Stacks\ProjectStack;
use App\Support\Certificate;
use App\Support\Compose;

class UpCommand extends ProjectCommand
{
    protected $signature = 'up';

    protected $description = 'Start the project in the current directory';

    public function handle(ProjectStack $stack, GlobalStack $global, Compose $compose, Certificate $certificate, Provisioner $provisioner): int
    {
        // Report mistakes in flight.yaml before anything is started.
        $stack->validate();
        $steps = $provisioner->steps($stack);

        $this->step(sprintf(
            'Certificate %s for %s',
            $certificate->ensure() ? 'issued' : 'valid',
            $certificate->wildcard(),
        ));

        // The project is only reachable through the global stack's proxy.
        $this->composing(
            'Starting the Flight stack',
            'Flight stack running',
            fn ($output) => $compose->up($global, $output),
        );

        $this->composing(
            'Starting the project',
            'Project started',
            fn ($output) => $compose->up($stack, $output),
        );

        $this->provision($provisioner, $stack, $steps);

        $this->projectSummary('Project running', $stack);

        return self::SUCCESS;
    }
}
