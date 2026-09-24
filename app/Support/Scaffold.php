<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Routed;
use App\Services\Service;
use App\Stacks\Stack;

/**
 * Writes a stack's compose file from its services. The file is overwritten
 * on every run.
 */
class Scaffold
{
    public function write(Stack $stack): void
    {
        $services = [];
        $volumes = [];

        $stack->prepare();

        foreach ($stack->services() as $service) {
            $service->prepare();

            foreach ($service->composeServices() as $name => $definition) {
                // Only what the proxy serves joins its network. Databases and
                // workers stay in the stack's own network.
                $routed = $name === $service->composeName() && $service instanceof Routed;

                if ($routed) {
                    $definition['labels'] = [...$definition['labels'] ?? [], ...$this->labels($stack, $service)];
                }

                $definition['networks'] ??= $routed ? $stack->serviceNetworks() : ['default'];

                $services[$name] = $definition;
            }

            $volumes += $service->volumes();
        }

        YamlFile::write($stack->composeFile(), array_filter([
            'name' => $stack->name(),
            'services' => $services,
            'networks' => $stack->networks(),
            // An empty section is left out.
            'volumes' => $volumes,
        ]), $stack->composeNote());
    }

    /**
     * Traefik labels that route the service's hostnames to its origin.
     *
     * @return array<string, string>
     */
    protected function labels(Stack $stack, Service&Routed $service): array
    {
        $origin = (array) parse_url($service->origin());
        $scheme = $origin['scheme'] ?? 'http';

        // Router names are global in Traefik, so prefix them with the stack.
        $router = $stack->name().'-'.$service->name();

        $rule = implode(' || ', array_map(
            fn (string $hostname): string => "Host(`{$hostname}`)",
            $service->hostnames(),
        ));

        return [
            'traefik.enable' => 'true',
            "traefik.http.routers.{$router}.rule" => $rule,
            "traefik.http.services.{$router}.loadbalancer.server.port" => (string) ($origin['port'] ?? 80),
            ...($scheme === 'http' ? [] : ["traefik.http.services.{$router}.loadbalancer.server.scheme" => $scheme]),
        ];
    }
}
