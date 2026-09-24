<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Commands\FlightCommand;
use App\Exceptions\FlightException;
use App\Provisioning\Provisioner;
use App\Stacks\ProjectStack;
use Closure;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectCommand extends FlightCommand
{
    /**
     * The project's own compose files can define any service, so compose
     * checks those names itself.
     */
    protected function service(ProjectStack $stack, ?string $name): string
    {
        $services = array_merge(...array_map(fn ($service): array => $service->composeNames(), $stack->services()));

        if ($name === null) {
            return $services[0];
        }

        if (! in_array($name, $services, true) && $stack->project()->ownComposeFiles() === []) {
            throw FlightException::make(
                "The project has no \"{$name}\" service.",
                'Expected one of: '.implode(', ', $services).'.',
            );
        }

        return $name;
    }

    protected function passthrough(): Closure
    {
        return fn (string $type, string $buffer) => $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
    }

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
            ['Directory', $this->displayPath($project->root())],
        ]);
    }
}
