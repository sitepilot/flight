<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Service;
use App\Support\GlobalConfig;
use App\Support\StackConfig;
use Illuminate\Contracts\Container\Container;

/**
 * A compose project built from a config file's services. Everything that
 * differs between the global stack and a project comes from its config.
 * The subclasses only exist so commands can ask for either one.
 */
abstract class Stack
{
    /** @var array<int, Service>|null */
    protected ?array $services = null;

    public function __construct(
        protected StackConfig $config,
        protected GlobalConfig $global,
        protected Container $container,
    ) {}

    public function config(): StackConfig
    {
        return $this->config;
    }

    /**
     * The compose project name, e.g. "flight".
     */
    public function name(): string
    {
        return $this->config->stackName();
    }

    public function directory(): string
    {
        return $this->config->directory();
    }

    public function filesDirectory(): string
    {
        return $this->config->filesDirectory();
    }

    public function composeFile(): string
    {
        return $this->config->composeFile();
    }

    public function composeNote(): string
    {
        return $this->config->composeNote();
    }

    public function prepare(): void
    {
        $this->config->prepare();
    }

    /**
     * The generated file, then the override file when it exists.
     *
     * @return array<int, string>
     */
    public function composeFiles(): array
    {
        $override = $this->config->overrideFile();

        return is_file($override)
            ? [$this->composeFile(), $override]
            : [$this->composeFile()];
    }

    /**
     * @return array<int, Service>
     */
    public function services(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $types = (array) config('flight.services');
        $routed = 0;

        $services = [];

        foreach ($this->config->services() as $name => $options) {
            $type = $options['type'];

            if (! is_string($type) || ! isset($types[$type])) {
                throw $this->config->invalid(
                    'Expected one of: '.implode(', ', array_keys($types)).'.',
                    "services.{$name}.type",
                );
            }

            /** @var class-string<Service> $class */
            $class = $types[$type];

            $services[] = $this->container->make($class, [
                'stack' => $this,
                'name' => (string) $name,
                'options' => $options,
                'label' => $class::routes() ? $this->config->label((string) $name, $routed++) : null,
            ]);
        }

        $this->ensureUniqueHostnames($services);

        return $this->services = $services;
    }

    public function service(string $name): ?Service
    {
        foreach ($this->services() as $service) {
            if ($service->name() === $name) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Building the services validates their options.
     */
    public function validate(): void
    {
        $this->services();
    }

    /**
     * The stack that owns the shared network uses it as its default network.
     * The others join it next to their own default network, so their
     * services can still reach each other.
     *
     * @return array<string, mixed>
     */
    public function networks(): array
    {
        return $this->config->ownsNetwork()
            ? ['default' => ['name' => $this->global->network()]]
            : ['flight' => ['name' => $this->global->network(), 'external' => true]];
    }

    /**
     * The networks every service joins.
     *
     * @return array<int, string>
     */
    public function serviceNetworks(): array
    {
        return array_values(array_unique(['default', ...array_keys($this->networks())]));
    }

    /**
     * Variables passed to compose, so override files can use ${FLIGHT_DOMAIN}
     * and the like.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $environment = [...$this->global->environment(), ...$this->config->environment()];

        foreach ($this->services() as $service) {
            $environment = [...$environment, ...$service->environment()];
        }

        return $environment;
    }

    /**
     * Otherwise Traefik would pick one of the services at random.
     *
     * @param  array<int, Service>  $services
     */
    protected function ensureUniqueHostnames(array $services): void
    {
        $claimed = [];

        foreach ($services as $service) {
            foreach ($service->hostnames() as $hostname) {
                if (isset($claimed[$hostname])) {
                    throw $this->config->invalid(
                        "Expected {$hostname} to be served once, but services.{$claimed[$hostname]} already serves it.",
                        "services.{$service->name()}.hostnames",
                    );
                }

                $claimed[$hostname] = $service->name();
            }
        }
    }
}
