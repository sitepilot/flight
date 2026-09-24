<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Compose;
use App\Support\StackConfig;
use App\Support\Variables;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs a project's provisioning steps in its containers: the recipe's first,
 * then those in flight.yaml.
 */
class Provisioner
{
    public function __construct(
        protected Compose $compose,
        protected Container $container,
        protected Variables $variables,
    ) {}

    /**
     * Checked here, so a mistake is reported before anything is started.
     */
    public function steps(ProjectStack $stack): array
    {
        $project = $stack->project();

        $steps = [
            ...($project->recipe()?->provision($this->container->make(Context::class, ['stack' => $stack])) ?? []),
            ...$project->provision(),
        ];

        // The project's own compose files can define any service, so compose
        // checks those names itself.
        $services = array_merge(...array_map(fn ($service): array => $service->composeNames(), $stack->services()));
        $known = fn (?string $service): bool => in_array($service, $services, true) || ($service !== null && $project->ownComposeFiles() !== []);
        $app = $stack->service(StackConfig::APP)?->composeName();

        foreach ($steps as $step) {
            if ($step->service === null && $app !== null) {
                $step->service = $app;
            }

            if ($step->command === null || ! $known($step->service)) {
                throw FlightException::make(
                    "Step \"{$step->name}\" needs a command and one of the project's services.",
                    'Expected a service such as: '.implode(', ', $services).'.',
                );
            }

            $this->variables->forStep($project, $step);
        }

        return $steps;
    }

    public function run(ProjectStack $stack, Step $step, ?Closure $output = null): bool
    {
        if ($step->unless !== null && $this->exec($stack, $step, $step->unless)->successful()) {
            return false;
        }

        $result = $this->exec($stack, $step, (string) $step->command, $output);

        if ($result->failed()) {
            throw FlightException::fromProcess($result, "Step \"{$step->name}\" failed.");
        }

        return true;
    }

    protected function exec(ProjectStack $stack, Step $step, string $command, ?Closure $output = null): ProcessResult
    {
        $env = $this->variables->forStep($stack->project(), $step);

        return $this->compose->exec($stack, (string) $step->service, $this->inDirectory($step, $command), $output, $env);
    }

    protected function inDirectory(Step $step, string $command): string
    {
        return $step->dir === null ? $command : 'cd '.escapeshellarg($step->dir).' && '.$command;
    }
}
