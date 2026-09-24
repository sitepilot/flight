<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Exceptions\FlightException;
use App\Services\Routed;
use App\Services\Service;
use App\Stacks\ProjectStack;
use App\Support\Compose;
use App\Support\Tunnel;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ShareCommand extends ProjectCommand implements SignalableCommandInterface
{
    protected $signature = 'share
        {service? : The service to share, defaults to the app}
        {--direct : Send the public hostname to the app, instead of its own}';

    protected $description = 'Share the project at a temporary public URL';

    /**
     * Output that has not made a whole line yet.
     */
    protected string $pending = '';

    /**
     * The running tunnel, once it has a URL.
     */
    protected ?InvokedProcess $tunnel = null;

    protected bool $stopping = false;

    public function handle(ProjectStack $stack, Compose $compose, Tunnel $tunnel): int
    {
        $service = $this->sharedService($stack);

        $running = $compose->run($stack, ['ps', '--status', 'running', '--quiet', $service->name()]);

        if (trim($running->output()) === '') {
            throw FlightException::make('The project is not running.', 'Start it first with: flight up');
        }

        $this->composing('Preparing the tunnel', 'Tunnel ready', fn ($output) => $tunnel->build($output));

        $log = '';
        $process = $tunnel->start($stack, $service, ! $this->option('direct'), function (string $type, string $buffer) use (&$log) {
            $log .= $buffer;
        });

        $url = $this->running('Opening the tunnel', function () use ($process, $tunnel, &$log): ?string {
            while ($process->running() && Tunnel::url($log) === null) {
                usleep(100_000);
            }

            $url = Tunnel::url($log);

            // Show the URL once it works, which takes a few more seconds.
            $until = time() + 30;

            while ($url !== null && $process->running() && ! $tunnel->resolves($url) && time() < $until) {
                usleep(500_000);
            }

            return $process->running() ? $url : null;
        });

        if ($url === null) {
            throw $this->failure($log);
        }

        $this->step('Tunnel open');
        $this->sharedSummary($stack, $service, $url);

        $this->tunnel = $process;

        $this->show($log);

        $result = $process->wait(fn (string $type, string $buffer) => $this->show($buffer));

        if ($this->stopping) {
            $this->step('Stopped sharing');

            return self::SUCCESS;
        }

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * Without the pcntl extension, Ctrl+C still reaches the container
     * directly, but Flight can't say it stopped.
     *
     * @return array<int, int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    /**
     * Once the tunnel is open, stop cloudflared and wait for the container
     * to stop and remove itself. Before that, exit as usual.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->tunnel === null) {
            return 128 + $signal;
        }

        $this->stopping = true;
        $this->tunnel->signal(\SIGINT);

        return false;
    }

    /**
     * The service asked for, or else the app. Only a service with a URL of
     * its own can be shared.
     */
    protected function sharedService(ProjectStack $stack): Service&Routed
    {
        $name = $this->service($stack, $this->argument('service'));
        $service = $stack->service($name);

        if (! $service instanceof Routed) {
            throw FlightException::make(
                "The \"{$name}\" service has no URL to share.",
                'Share a service that is served at a URL, such as the app.',
            );
        }

        return $service;
    }

    protected function sharedSummary(ProjectStack $stack, Service&Routed $service, string $url): void
    {
        $rows = [
            ['Project', $stack->project()->name()],
            ['', ''],
            [$service->name(), $url],
            ['Local', 'https://'.$service->hostnames()[0]],
        ];

        $width = max(11, ...array_map(fn (array $row): int => mb_strlen($row[0]) + 2, $rows));

        $this->panel('Project shared', array_map(
            fn (array $row): array => [str_pad($row[0], $width), $row[1]],
            $rows,
        ), 'cyan');

        $this->note('Anyone with the URL can open it. Press Ctrl+C to stop sharing.');
        $this->line('');
    }

    /**
     * Show cloudflared's errors and warnings as they arrive, or everything
     * with -v. Leave out those of shutting down.
     */
    protected function show(string $buffer): void
    {
        if ($this->stopping && ! $this->output->isVerbose()) {
            return;
        }

        $this->pending .= $buffer;

        while (($end = strpos($this->pending, "\n")) !== false) {
            $line = substr($this->pending, 0, $end);
            $this->pending = substr($this->pending, $end + 1);

            if ($this->output->isVerbose() || preg_match('/ (ERR|WRN) /', $line)) {
                $this->line('  <fg=gray>'.OutputFormatter::escape($line).'</>');
            }
        }
    }

    /**
     * Why the tunnel stopped before it had a URL: docker's error, or the
     * last thing cloudflared logged that was not routine.
     */
    protected function failure(string $log): FlightException
    {
        if (str_contains($log, 'is already in use')) {
            return FlightException::make('The service is already shared.', 'Stop the other `flight share` first.');
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $log)),
            fn (string $line): bool => $line !== '' && ! str_contains($line, ' INF '),
        );

        // Without the time and level, e.g. "2026-09-24T07:17:47Z ERR ".
        $last = preg_replace('/^\S+Z [A-Z]{3} /', '', (string) end($lines));

        return FlightException::make('Could not open the tunnel.', (string) Str::of($last)->limit(300));
    }
}
