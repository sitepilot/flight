<?php

declare(strict_types=1);

namespace App\Provisioning;

/**
 * A shell command run in one of the project's services, e.g.
 * Step::make('Install dependencies')->in('php')->run('composer install')
 * ->unless('test -d vendor').
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

    /**
     * Variables passed to the command and its check, e.g. a license key.
     *
     * @var array<int, string>
     */
    public array $env = [];

    public function __construct(public string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * @param  array{name: string, service: string, run: string, unless?: ?string, dir?: ?string, env?: ?array<int, string>}  $step
     */
    public static function fromArray(array $step): static
    {
        $instance = static::make($step['name'])->in($step['service'])->run($step['run']);

        $instance->unless = $step['unless'] ?? null;
        $instance->dir = $step['dir'] ?? null;
        $instance->env = $step['env'] ?? [];

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

    public function env(string ...$names): static
    {
        $this->env = $names;

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
