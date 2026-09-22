<?php

declare(strict_types=1);

namespace App\Support;

use App\Stacks\Stack;

/**
 * Turns a stack's services into its generated compose file.
 *
 * Rewritten on every run, as the bash version did and documented: the file
 * is owned by Flight, user services belong in compose.override.yaml.
 */
class Scaffold
{
    public function write(Stack $stack): void
    {
        $services = [];
        $networks = [];
        $volumes = [];

        foreach ($stack->services() as $service) {
            $service->prepare();

            $services[$service->name()] = $service->definition();
            $networks += $service->networks();
            $volumes += $service->volumes();
        }

        YamlFile::write($stack->composeFile(), array_filter([
            'name' => $stack->name(),
            'services' => $services,
            // Dropped when empty; a stack always has at least one service.
            'networks' => $networks,
            'volumes' => $volumes,
        ]), 'add your own services to compose.override.yaml instead.');
    }
}
