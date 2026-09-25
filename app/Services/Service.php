<?php

declare(strict_types=1);

namespace App\Services;

use App\Stacks\Stack;
use App\Support\GlobalConfig;
use App\Support\HasOptions;

/**
 * A service in a stack's compose file. Its options are validated when it's
 * built, so mistakes are reported before anything is written or started.
 */
abstract class Service
{
    use HasOptions;

    /**
     * A single DNS label, since the wildcard certificate covers one level
     * under the domain.
     */
    public const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Where it's set in the config, e.g. "app" or "services.db".
     */
    protected string $path;

    protected string $type;

    public function __construct(
        protected Stack $stack,
        protected GlobalConfig $global,
        protected string $name,
        array $options = [],
        protected ?string $label = null,
        ?string $path = null,
    ) {
        $this->path = $path ?? "services.{$name}";
        $this->type = (string) ($options['type'] ?? $name);

        unset($options['type']);

        // Allow `hostnames: shop` as well as a list.
        if (is_string($options['hostnames'] ?? null)) {
            $options['hostnames'] = [$options['hostnames']];
        }

        // Allow `queue: {run: …}` as well as `queue: …`.
        if (is_array($options['workers'] ?? null)) {
            $options['workers'] = array_map(
                fn (mixed $worker): mixed => is_array($worker) && array_keys($worker) === ['run'] ? $worker['run'] : $worker,
                $options['workers'],
            );
        }

        $this->configure($stack->config(), $this->path, $options, ['version' => 'type']);

        if ($this->workers() !== [] && array_is_list($this->workers())) {
            throw $stack->config()->invalid('Expected workers to map names to commands, such as `queue: php artisan queue:work`.', "{$this->path}.workers");
        }

        foreach (array_keys($this->workers()) as $worker) {
            if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string) $worker)) {
                throw $stack->config()->invalid('Expected a lowercase worker name such as "queue".', "{$this->path}.workers.{$worker}");
            }
        }
    }

    abstract public function definition(): array;

    /**
     * Commands by worker name. Workers run on the service's image, with its
     * mounts and environment, each in a container of its own.
     */
    public function workers(): array
    {
        return $this->options['workers'] ?? [];
    }

    public function composeName(): string
    {
        return $this->name;
    }

    public function composeNames(): array
    {
        return [$this->composeName(), ...array_keys($this->workers())];
    }

    public function composeServices(): array
    {
        $definition = $this->definition();

        if ($this->workers() === []) {
            return [$this->composeName() => $definition];
        }

        // Name a built image, so the workers can run it too.
        if (isset($definition['build'])) {
            $definition['image'] = $this->workerImage();
        }

        $services = [$this->composeName() => $definition];

        foreach ($this->workers() as $worker => $command) {
            $services[$worker] = $this->workerDefinition($definition, $command);
        }

        return $services;
    }

    /**
     * The service's definition, running the worker's command. Its build,
     * ports, labels and healthcheck belong to the service alone.
     */
    protected function workerDefinition(array $definition, string $command): array
    {
        $built = isset($definition['build']);

        unset($definition['build'], $definition['ports'], $definition['labels'], $definition['healthcheck'], $definition['pull_policy']);

        return [
            ...$definition,
            // Built by the service itself, so never pulled.
            ...($built ? ['pull_policy' => 'never'] : []),
            'depends_on' => [$this->composeName()],
            'command' => ['sh', '-c', $command],
            // A worker is healthy while it runs. The image's own check is
            // the service's, and `up --wait` rejects a disabled one, so this
            // one passes, checked every second while the worker starts.
            'healthcheck' => [
                'test' => ['CMD', 'true'],
                'start_period' => '10s',
                'start_interval' => '1s',
            ],
        ];
    }

    protected function workerImage(): string
    {
        return $this->stack->name().'-'.$this->name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function hostnames(): array
    {
        if ($this->label === null) {
            return [];
        }

        return array_map(
            fn (string $label): string => $label.'.'.$this->global->domain(),
            array_values(array_unique([$this->label, ...$this->options['hostnames'] ?? []])),
        );
    }

    public function volumes(): array
    {
        return [];
    }

    /**
     * Variables compose files can use.
     */
    public function environment(): array
    {
        return [];
    }

    /**
     * For the summary, e.g. "MariaDB 11.8 at mariadb:3306".
     */
    public function description(): string
    {
        return '';
    }

    public function summary(): array
    {
        return [];
    }

    public function prepare(): void {}

    protected function directory(string $name): string
    {
        return $this->stack->filesDirectory().'/'.$this->name.'/'.$name;
    }

    /**
     * As compose reads it, e.g. "./.flight/app/build", or "." for the
     * project.
     */
    protected function composePath(?string $name = null): string
    {
        return $this->relativePath($name === null ? $this->stack->config()->directory() : $this->directory($name));
    }

    /**
     * Relative to the compose project directory, which is a subfolder when
     * the project's compose files are in one.
     */
    private function relativePath(string $path): string
    {
        $from = array_values(array_filter(explode('/', $this->stack->directory())));
        $to = array_values(array_filter(explode('/', $path)));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $parts = [...array_fill(0, count($from), '..'), ...$to];

        return $parts === [] ? '.' : './'.implode('/', $parts);
    }

    protected function allMessages(): array
    {
        return [
            'version.in' => "Expected a {$this->type} version, one of: :values.",
            ...$this->messages(),
        ];
    }
}
