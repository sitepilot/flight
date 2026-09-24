<?php

declare(strict_types=1);

namespace App\Provisioning;

/**
 * A shell command run in one of the project's services, e.g.
 * `Step::make('Install')->run('composer install')->unless('test -d vendor')`.
 */
class Step
{
    /**
     * The service to run in, or null for the app.
     */
    public ?string $service = null;

    public ?string $command = null;

    /**
     * A command that exits 0 once the step is done, so it's skipped.
     */
    public ?string $unless = null;

    /**
     * The directory to run in, relative to the container's working directory.
     */
    public ?string $dir = null;

    public array $env = [];

    public function __construct(public string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public static function fromArray(array $step): static
    {
        $instance = static::make($step['name'])->run($step['run']);

        $instance->service = $step['service'] ?? null;
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
