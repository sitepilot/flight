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

            $definition = $service->definition();
            $definition['networks'] ??= $stack->serviceNetworks();

            $services[$service->name()] = $definition;
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
