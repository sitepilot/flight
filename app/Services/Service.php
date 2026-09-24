<?php

declare(strict_types=1);

namespace App\Services;

use App\Stacks\Stack;
use App\Support\GlobalConfig;
use App\Support\HasOptions;

/**
 * One service in a stack's compose file, built from its options in
 * config.yaml or flight.yaml. The options are validated when the service is
 * built, so mistakes are reported before anything is written or started.
 *
 * A service that is Routed is served over HTTPS. A service that declares a
 * `workers` option runs them next to it, on its image.
 */
abstract class Service
{
    use HasOptions;

    /**
     * A single DNS label, since the wildcard certificate covers only one
     * level under the domain.
     */
    public const string LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Where it is set in the config, e.g. "app" or "services.db".
     */
    protected string $path;

    /**
     * Its type, e.g. "mariadb".
     */
    protected string $type;

    /**
     * @param  array<string, mixed>  $options
     */
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

        // The version comes from the type, e.g. `type: mariadb:11.8`.
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

    /**
     * The services.<name> fragment of the compose file. The stack adds its
     * networks unless the fragment sets its own.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Commands by worker name: background processes, such as a queue
     * worker, on the service's image with its mounts and environment, each
     * in a container of its own. Only for a service that declares `workers`.
     *
     * @return array<string, string>
     */
    public function workers(): array
    {
        return $this->options['workers'] ?? [];
    }

    /**
     * The service's name in the compose file, e.g. "app".
     */
    public function composeName(): string
    {
        return $this->name;
    }

    /**
     * The names of this service's compose services, e.g. "app", "queue".
     *
     * @return array<int, string>
     */
    public function composeNames(): array
    {
        return [$this->composeName(), ...array_keys($this->workers())];
    }

    /**
     * The compose services for this service and its workers, by name. A
     * worker goes by its own name, e.g. "queue"; the stack checks that no
     * two names in the project are the same.
     *
     * @return array<string, array<string, mixed>>
     */
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
     * The service's own definition, with its command instead and without
     * what belongs to the service alone: its build, ports, addresses and
     * its healthcheck, which checks what the service runs.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
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
            'healthcheck' => ['disable' => true],
        ];
    }

    /**
     * E.g. "flight-myapp-app".
     */
    protected function workerImage(): string
    {
        return $this->stack->name().'-'.$this->name;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The hostnames this service is served at. Only a Routed service gets a
     * label from the stack.
     *
     * @return array<int, string>
     */
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

    /**
     * Top-level volumes this service needs.
     *
     * @return array<string, mixed>
     */
    public function volumes(): array
    {
        return [];
    }

    /**
     * Variables passed to compose, so override files can use them.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }

    /**
     * What the service is, for the summary of a service without an address,
     * e.g. "MariaDB 11.8 at mariadb:3306".
     */
    public function description(): string
    {
        return '';
    }

    /**
     * Rows for the summary shown once the stack runs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function summary(): array
    {
        return [];
    }

    /**
     * Write any files this service needs before compose runs.
     */
    public function prepare(): void {}

    /**
     * A folder of this service in the stack's files directory, for writing
     * files: "build" for files Flight generates, "data" for what the service
     * keeps.
     */
    protected function directory(string $name): string
    {
        return $this->stack->filesDirectory().'/'.$this->name.'/'.$name;
    }

    /**
     * The same folder as compose reads it, e.g. "./.flight/app/build", or
     * the project itself without a name: ".".
     */
    protected function composePath(?string $name = null): string
    {
        return $this->relativePath($name === null ? $this->stack->config()->directory() : $this->directory($name));
    }

    /**
     * Relative to the compose project directory, so "./.." and the like
     * when the project's compose files are in a subfolder.
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

    /**
     * @return array<string, string>
     */
    protected function allMessages(): array
    {
        return [
            'version.in' => "Expected a {$this->type} version, one of: :values.",
            ...$this->messages(),
        ];
    }
}
