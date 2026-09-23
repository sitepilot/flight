<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Commands\FlightCommand;
use App\Provisioning\Provisioner;
use App\Provisioning\Step;
use App\Stacks\ProjectStack;

/**
 * Output shared by the commands that manage the project in the current
 * directory.
 */
abstract class ProjectCommand extends FlightCommand
{
    /**
     * @param  array<int, Step>  $steps
     */
    protected function provision(Provisioner $provisioner, ProjectStack $stack, array $steps): void
    {
        foreach ($steps as $step) {
            $ran = $this->running($step->name, fn ($output) => $provisioner->run($stack, $step, $output));

            $ran ? $this->step($step->name) : $this->skipped($step->name.' (skipped)');
        }
    }

    protected function projectSummary(string $title, ProjectStack $stack): void
    {
        $project = $stack->project();

        $this->summary($title, $stack, [
            ['Project', $project->name()],
            ...($project->recipe() === null ? [] : [['Recipe', $project->recipe()->name()]]),
        ], [
            ['Directory', $this->displayPath($stack->directory())],
        ]);
    }
}
