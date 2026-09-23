<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Compose;
use App\Support\Variables;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs a project's provisioning steps in its containers: the recipe's
 * first, then those in flight.yaml.
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
     *
     * @return array<int, Step>
     */
    public function steps(ProjectStack $stack): array
    {
        $project = $stack->project();

        $steps = [
            ...($project->recipe()?->provision($this->container->make(Context::class, ['stack' => $stack])) ?? []),
            ...$project->provision(),
        ];

        $services = array_map(fn ($service): string => $service->name(), $stack->services());

        foreach ($steps as $step) {
            if ($step->command === null || ! in_array($step->service, $services, true)) {
                throw FlightException::make(
                    "Step \"{$step->name}\" needs a command and one of the project's services.",
                    'Expected a service such as: '.implode(', ', $services).'.',
                );
            }

            foreach ($step->env as $name) {
                if ($this->variables->get($project, $name) === null) {
                    throw FlightException::make(
                        "Step \"{$step->name}\" needs {$name}.",
                        $this->variables->hint($project),
                    );
                }
            }
        }

        return $steps;
    }

    /**
     * Run a step unless its check says it is done.
     *
     * @return bool whether the step ran
     */
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
        $env = [];

        foreach ($step->env as $name) {
            $env[$name] = (string) $this->variables->get($stack->project(), $name);
        }

        return $this->compose->exec($stack, (string) $step->service, $this->inDirectory($step, $command), $output, $env);
    }

    /**
     * Relative to the container's working directory, which for PHP is the
     * app, e.g. "assets".
     */
    protected function inDirectory(Step $step, string $command): string
    {
        return $step->dir === null ? $command : 'cd '.escapeshellarg($step->dir).' && '.$command;
    }
}
