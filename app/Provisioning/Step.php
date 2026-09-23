<?php

declare(strict_types=1);

namespace App\Provisioning;

/**
 * A shell command run in one of the project's services, e.g.
 * Step::make('Install WordPress')->in('php')->run('wp core install …')
 * ->unless('wp core is-installed').
 */
class Step
{
    public ?string $service = null;

    public ?string $command = null;

    /**
     * A command that exits 0 once the step is done, so it is skipped.
     */
    public ?string $unless = null;

    /**
     * Where to run, relative to the container's working directory.
     */
    public ?string $dir = null;

    public function __construct(public string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * @param  array{name: string, service: string, run: string, unless?: ?string, dir?: ?string}  $step
     */
    public static function fromArray(array $step): static
    {
        $instance = static::make($step['name'])->in($step['service'])->run($step['run']);

        $instance->unless = $step['unless'] ?? null;
        $instance->dir = $step['dir'] ?? null;

        return $instance;
    }

    public function in(string $service): static
    {
        $this->service = $service;

        return $this;
    }

    public function run(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function dir(string $dir): static
    {
        $this->dir = $dir;

        return $this;
    }

    public function unless(string $command): static
    {
        $this->unless = $command;

        return $this;
    }
}
