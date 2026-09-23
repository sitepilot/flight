<?php

declare(strict_types=1);

namespace App\Support;

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
                $definition['networks'] ??= $stack->serviceNetworks();
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
}
