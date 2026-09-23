<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Commands\FlightCommand;
use App\Services\Service;
use App\Stacks\GlobalStack;
use App\Support\Compose;

/**
 * Output shared by the commands that manage the global stack.
 */
abstract class StackCommand extends FlightCommand
{
    protected function recreate(GlobalStack $stack, Compose $compose): void
    {
        $this->composing(
            'Recreating the stack',
            'Stack recreated',
            fn ($output) => $compose->recreate($stack, $output),
        );

        $this->stackSummary('Stack running', $stack);
    }

    protected function stackSummary(string $title, GlobalStack $stack): void
    {
        $config = $stack->config();

        $this->summary($title, $stack, [
            ['Domain', '*.'.$config->domain()],
            ['Network', $config->network()],
            ...array_merge(...array_map(fn (Service $service): array => $service->summary(), $stack->services())),
        ], [
            ['Config', $this->displayPath($config->directory())],
        ]);

        $this->note(sprintf(
            'Expose a project by adding a flight.yaml and running `flight up`, '.
            'or by joining the `%s` network and labeling it '.
            'traefik.http.routers.<name>.rule=Host(`<name>.%s`)',
            $config->network(),
            $config->domain()
        ));
    }
}
