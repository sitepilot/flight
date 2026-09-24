<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Routed;
use App\Services\Service;
use App\Support\GlobalConfig;
use App\Support\StackConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

/**
 * A compose project built from a config file's services. Everything that
 * differs between the global stack and a project comes from its config; the
 * subclasses only exist so commands can ask for either one.
 */
abstract class Stack
{
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

    public function name(): string
    {
        return $this->config->stackName();
    }

    /**
     * The folder of the first file listed under `compose`, as for `docker
     * compose -f`, or else the config's.
     */
    public function directory(): string
    {
        $files = $this->config->ownComposeFiles();

        return $files === [] ? $this->config->directory() : dirname($files[0]);
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
        $files = $this->composeFiles();

        if (count($files) === 1) {
            return $this->config->composeNote();
        }

        $order = array_map(
            fn (string $file, int $i): string => '  '.($i + 1).'. '.Str::after($file, $this->config->directory().'/').($i === 0 ? ' (this file)' : ''),
            $files,
            array_keys($files),
        );

        return implode("\n", [
            $this->config->composeNote(),
            '',
            'Flight runs these files in this order; later files can change earlier ones:',
            ...$order,
        ]);
    }

    public function prepare(): void
    {
        $this->config->prepare();
    }

    public function composeFiles(): array
    {
        return [$this->composeFile(), ...$this->config->ownComposeFiles()];
    }

    public function services(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $types = (array) config('flight.services');

        $services = [];

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
     * The others join it next to their own, so their services can still reach
     * each other.
     */
    public function networks(): array
    {
        return $this->config->ownsNetwork()
            ? ['default' => ['name' => $this->global->network()]]
            : ['flight' => ['name' => $this->global->network(), 'external' => true]];
    }

    public function network(): string
    {
        return $this->config->ownsNetwork() ? $this->global->network() : $this->name().'_default';
    }

    public function serviceNetworks(): array
    {
        return array_values(array_unique(['default', ...array_keys($this->networks())]));
    }

    public function environment(): array
    {
        $environment = [...$this->global->environment(), ...$this->config->environment()];

        foreach ($this->services() as $service) {
            $environment = [...$environment, ...$service->environment()];
        }

        return $environment;
    }

    /**
     * Compose would merge a worker into a service or another worker of the
     * same name.
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

    protected function path(Service $service): string
    {
        return $service->name() === StackConfig::APP ? 'app' : "services.{$service->name()}";
    }

    /**
     * Otherwise Traefik would pick one of the services at random.
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
