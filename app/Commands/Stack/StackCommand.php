<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Commands\FlightCommand;
use App\Stacks\GlobalStack;
use App\Support\Compose;
use App\Support\Scaffold;

use function Laravel\Prompts\spin;

/**
 * A command that drives the docker compose stack.
 *
 * The preconditions live here rather than on FlightCommand so that commands
 * which are not compose wrappers — stack:config — simply do not inherit
 * them, instead of opting out by removing steps from a shared method.
 */
abstract class StackCommand extends FlightCommand
{
    protected GlobalStack $stack;

    protected Compose $compose;

    protected function bootstrap(): void
    {
        parent::bootstrap();

        $this->stack = $this->laravel->make(GlobalStack::class);
        $this->compose = $this->laravel->make(Compose::class);

        $this->config->load();

        $this->laravel->make(Scaffold::class)->write($this->stack);
        $this->step('Configuration written');
    }

    /**
     * Recreate the containers and show the summary. Shared by stack:restart
     * and the tail of stack:secure.
     */
    protected function recreate(): void
    {
        $this->composing(
            'Recreating the stack',
            'Stack recreated',
            fn ($output) => $this->compose->recreate($this->stack, $output),
        );

        $this->summary('Stack running');
    }

    /**
     * Run a docker compose action, streaming its output under -v and showing
     * a spinner otherwise. Failures always surface the captured output.
     *
     * Takes both wordings, because the spinner runs while the work is still
     * happening and the step line is printed once it is done.
     */
    protected function composing(string $running, string $done, callable $action): void
    {
        if ($this->output->isVerbose()) {
            $this->line('');
            $action(fn ($type, $buffer) => $this->output->write($buffer));
        } else {
            spin(fn () => $action(null), $running.'…');
        }

        $this->step($done);
    }

    /**
     * The settings someone actually needs, plus a row per routed hostname so
     * a second service shows up for free.
     */
    protected function summary(string $title): void
    {
        $rows = [
            ['Domain', '*.'.$this->config->domain()],
            ['Network', $this->config->network()],
            ['HTTP', ':'.$this->config->httpPort().'  → redirects to HTTPS'],
            ['HTTPS', ':'.$this->config->httpsPort()],
        ];

        foreach ($this->stack->services() as $service) {
            foreach ($service->hostnames() as $hostname) {
                $rows[] = [ucfirst($service->name()), 'https://'.$hostname];
            }
        }

        $rows[] = ['Config', $this->displayPath($this->config->directory())];

        $this->panel($title, array_map(
            fn (array $row): array => [str_pad($row[0], 11), $row[1]],
            $rows,
        ), 'cyan');

        $this->note(sprintf(
            'Expose a project by joining the `%s` network and labelling it '.
            'traefik.http.routers.<name>.rule=Host(`<name>.%s`)',
            $this->config->network(),
            $this->config->domain()
        ));
    }
}
