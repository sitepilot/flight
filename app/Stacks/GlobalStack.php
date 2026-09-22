<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Service;
use App\Support\GlobalConfig;
use Illuminate\Contracts\Container\Container;

/**
 * The global stack: one compose project holding every Flight-managed
 * service, living in ~/.config/flight.
 */
class GlobalStack extends Stack
{
    public function __construct(
        protected GlobalConfig $config,
        protected Container $container,
    ) {}

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

    /**
     * @return array<int, Service>
     */
    public function services(): array
    {
        // Read on demand: commands are constructed during boot, so a
        // constructor-injected list would freeze too early to override.
        $services = array_map(
            fn (string $class): Service => $this->container->make($class),
            (array) config('flight.services'),
        );

        return array_values(array_filter($services, fn (Service $service): bool => $service->isEnabled()));
    }

    public function environment(): array
    {
        return [
            'FLIGHT_DOMAIN' => $this->config->domain(),
            'FLIGHT_NETWORK' => $this->config->network(),
            'FLIGHT_HTTP_PORT' => (string) $this->config->httpPort(),
            'FLIGHT_HTTPS_PORT' => (string) $this->config->httpsPort(),
            'FLIGHT_DOCKER_SOCK' => $this->config->dockerSocket(),
        ];
    }
}
