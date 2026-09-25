<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Commands\FlightCommand;
use App\Exceptions\FlightException;
use App\Provisioning\Provisioner;
use App\Services\Routed;
use App\Services\Service;
use App\Stacks\ProjectStack;
use App\Support\Compose;
use Closure;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectCommand extends FlightCommand
{
    public function __construct()
    {
        parent::__construct();

        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The name of a running project to use, instead of the current directory');
    }

    /**
     * The project's flight.yaml is searched for from the working directory,
     * so switching to the named project's root is enough.
     */
    protected function prepare(): void
    {
        $name = $this->option('project');

        if ($name === null) {
            return;
        }

        $root = $this->laravel->make(Compose::class)->flightProjects()[$name] ?? null;

        if ($root === null) {
            throw FlightException::make(
                "No running project named \"{$name}\".",
                'Run `flight list` to see the running projects.',
            );
        }

        chdir($root);
    }

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

    /**
     * The $verb, e.g. "share", completes the error for a service without a
     * URL.
     */
    protected function routedService(ProjectStack $stack, ?string $name, string $verb): Service&Routed
    {
        $name = $this->service($stack, $name);
        $service = $stack->service($name);

        if (! $service instanceof Routed) {
            throw FlightException::make(
                "The \"{$name}\" service has no URL to {$verb}.",
                ucfirst($verb).' a service that is served at a URL, such as the app.',
            );
        }

        return $service;
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
