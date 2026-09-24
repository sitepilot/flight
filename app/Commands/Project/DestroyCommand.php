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

        $lost = $project->ownComposeFiles() === []
            ? 'Its database and files in .flight are lost.'
            : 'The volumes in its compose files, such as a database, and its files in .flight are lost.';

        if (! $this->option('force') && ! $this->confirm(
            "Remove {$project->name()}'s containers, volumes and data? {$lost}",
        )) {
            $this->note('Nothing was removed.');

            return self::SUCCESS;
        }

        $this->composing(
            'Removing the project',
            'Containers and volumes removed',
            fn ($output) => $compose->destroy($stack, $output),
        );

        $project->removeFiles();

        $this->step('Data in .flight removed');

        $this->note('Run `flight up` to start over.');

        return self::SUCCESS;
    }
}
