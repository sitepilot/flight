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
        $networks = [];
        $volumes = [];

        $stack->prepare();

        foreach ($stack->services() as $service) {
            $service->prepare();

            $services[$service->name()] = $service->definition();
            $networks += $service->networks();
            $volumes += $service->volumes();
        }

        YamlFile::write($stack->composeFile(), array_filter([
            'name' => $stack->name(),
            'services' => $services,
            // Empty sections are left out.
            'networks' => $networks,
            'volumes' => $volumes,
        ]), $stack->composeNote());
    }
}
