<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Routed;
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

        $services = [];

        // The config has checked the types.
        foreach ($this->config->services() as $name => $options) {
            /** @var class-string<Service> $class */
            $class = $types[$options['type']];

            $services[] = $this->container->make($class, [
                'stack' => $this,
                'name' => (string) $name,
                'options' => $options,
                'label' => is_a($class, Routed::class, true) ? $this->config->label((string) $name) : null,
                'path' => $name === StackConfig::APP ? 'app' : "services.{$name}",
            ]);
        }

        $this->ensureUniqueNames($services);
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
     * The stack's own network, e.g. "flight-myapp_default", on which its
     * services reach each other by name.
     */
    public function network(): string
    {
        return $this->config->ownsNetwork() ? $this->global->network() : $this->name().'_default';
    }

    /**
     * The networks a routed service joins; the others only join the
     * stack's own network.
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
     * Workers go by their own name, which a service or another worker could
     * use too; compose would then merge them into one.
     *
     * @param  array<int, Service>  $services
     */
    protected function ensureUniqueNames(array $services): void
    {
        $claimed = [];

        foreach ($services as $service) {
            $claimed[$service->name()] = $this->path($service);
        }

        foreach ($services as $service) {
            foreach (array_keys($service->workers()) as $worker) {
                $path = "{$this->path($service)}.workers.{$worker}";

                if (isset($claimed[$worker])) {
                    throw $this->config->invalid("Expected \"{$worker}\" to be used once, but {$claimed[$worker]} already uses it.", $path);
                }

                $claimed[$worker] = $path;
            }
        }
    }

    /**
     * Where a service is set in the config: "app" or "services.<name>".
     */
    protected function path(Service $service): string
    {
        return $service->name() === StackConfig::APP ? 'app' : "services.{$service->name()}";
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
                        "Expected {$hostname} to be served once, but {$claimed[$hostname]} already serves it.",
                        "{$this->path($service)}.hostnames",
                    );
                }

                $claimed[$hostname] = $this->path($service);
            }
        }
    }
}
