<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Compose;

class DestroyCommand extends ProjectCommand
{
    protected $signature = 'destroy {--force : Skip the confirmation}';

    protected $description = 'Remove the project\'s containers, volumes and data';

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        $project = $stack->project();

        if (! $this->option('force') && ! $this->confirm(
            "Remove {$project->name()}'s containers, volumes and data? Its database and files in .flight are lost.",
        )) {
            $this->note('Nothing was removed.');

            return self::SUCCESS;
        }

        $this->composing(
            'Removing the project',
            'Containers and volumes removed',
            fn ($output) => $compose->destroy($stack, $output),
        );

        $kept = $project->removeFiles();

        $this->step('Data in .flight removed');

        $this->note(
            ($kept === [] ? '' : 'Kept your '.implode(' and ', $kept).' in .flight. ').
            'Run `flight up` to start over.',
        );

        return self::SUCCESS;
    }
}
