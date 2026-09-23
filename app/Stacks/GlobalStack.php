<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Service;
use App\Support\GlobalConfig;
use Illuminate\Contracts\Container\Container;

/**
 * The shared services every project uses, such as the Traefik proxy. Lives
 * in ~/.config/flight.
 */
class GlobalStack extends Stack
{
    /** @var array<int, Service>|null */
    protected ?array $services = null;

    public function __construct(
        protected GlobalConfig $config,
        protected Container $container,
    ) {}

    public function config(): GlobalConfig
    {
        return $this->config;
    }

    public function name(): string
    {
        return 'flight';
    }

    public function directory(): string
    {
        return $this->config->directory();
    }

    public function composeFile(): string
    {
        return $this->config->composeFile();
    }

    public function overrideFile(): ?string
    {
        return $this->config->overrideFile();
    }

    public function prepare(): void
    {
        $this->config->scaffold();
    }

    /**
     * @return array<int, Service>
     */
    public function services(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $services = array_map(
            fn (string $class): Service => $this->container->make($class),
            (array) config('flight.services'),
        );

        return $this->services = array_values(array_filter($services, fn (Service $service): bool => $service->isEnabled()));
    }

    public function environment(): array
    {
        return $this->config->environment();
    }
}
