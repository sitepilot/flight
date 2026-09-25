<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Stacks\Stack;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Runs docker compose for a stack, writing its compose file first so it's
 * never stale.
 */
class Compose
{
    public function __construct(protected Scaffold $scaffold) {}

    protected const array HINTS = [
        'Cannot connect to the Docker daemon' => 'Docker is installed but not running. Start Docker Desktop, or run: sudo systemctl start docker',
        'is not a docker command' => 'The Docker Compose plugin is missing. Install it from https://docs.docker.com/compose/install/',
    ];

    public function up(Stack $stack, ?Closure $output = null): void
    {
        // Wait until the containers run, or are healthy when they have a
        // healthcheck, so provisioning steps can use them.
        $this->run($stack, ['up', '-d', '--wait'], $output);
    }

    public function down(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['down', '--remove-orphans'], $output);
    }

    /**
     * Also removes the volumes, such as a database's data.
     */
    public function destroy(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['down', '--volumes', '--remove-orphans'], $output);
    }

    public function recreate(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['up', '-d', '--remove-orphans', '--force-recreate'], $output);
    }

    public function run(Stack $stack, array $arguments, ?Closure $output = null): ProcessResult
    {
        $this->ensureInstalled();

        $this->scaffold->write($stack);

        $result = $this->process($stack, $arguments, $output);

        if ($result->failed()) {
            throw $this->explain($result, 'docker compose '.implode(' ', $arguments).' failed.');
        }

        return $result;
    }

    /**
     * Returns the result rather than throwing, since a failing check is not
     * an error. Only the names of $env are on the command line; compose reads
     * their values from its environment, so secrets don't show in `ps`.
     */
    public function exec(Stack $stack, string $service, string $command, ?Closure $output = null, array $env = []): ProcessResult
    {
        $names = array_merge(...array_map(fn (string $name): array => ['-e', $name], array_keys($env)));

        return $this->process($stack, ['exec', '-T', ...$names, $service, 'sh', '-c', $command], $output, 3600, $env);
    }

    /**
     * Attached to the terminal when there is one, so shells and prompts work.
     */
    public function attach(Stack $stack, string $service, array $command, ?Closure $output = null): ProcessResult
    {
        return $this->stream($stack, ['exec', ...($this->hasTty() ? [] : ['-T']), $service, ...$command], $output);
    }

    public function logs(Stack $stack, string $service, bool $follow = false, ?string $tail = null, ?Closure $output = null): ProcessResult
    {
        return $this->stream($stack, [
            'logs',
            ...($follow ? ['--follow'] : []),
            ...($tail === null ? [] : ['--tail', $tail]),
            $service,
        ], $output);
    }

    public function projects(): array
    {
        $result = Process::run(['docker', 'compose', 'ls', '--format', 'json']);

        $projects = [];

        foreach ((array) json_decode($result->successful() ? $result->output() : '', true) as $project) {
            $projects[(string) ($project['Name'] ?? '')] = explode(',', (string) ($project['ConfigFiles'] ?? ''));
        }

        return $projects;
    }

    /**
     * The running Flight projects, as name ⇒ root directory, found by
     * Flight's own compose file in the project's .flight directory. Compose
     * doesn't list the files in the order they were passed.
     */
    public function flightProjects(): array
    {
        $projects = [];

        foreach ($this->projects() as $name => $files) {
            $file = Arr::first($files, fn (string $file): bool => str_ends_with($file, '/.flight/compose.yaml'));

            if (str_starts_with($name, 'flight-') && $file !== null) {
                $projects[substr($name, strlen('flight-'))] = dirname($file, 2);
            }
        }

        return $projects;
    }

    protected function stream(Stack $stack, array $arguments, ?Closure $output = null): ProcessResult
    {
        $this->ensureInstalled();

        $tty = $this->hasTty();

        return Process::env($stack->environment())
            ->forever()
            ->tty($tty)
            ->run([...$this->command($stack), ...$arguments], $tty ? null : $output);
    }

    protected function hasTty(): bool
    {
        return SymfonyProcess::isTtySupported();
    }

    protected function process(Stack $stack, array $arguments, ?Closure $output = null, int $timeout = 300, array $env = []): ProcessResult
    {
        return Process::env([...$stack->environment(), ...$env])
            ->timeout($timeout)
            ->run([...$this->command($stack), ...$arguments], $output);
    }

    /**
     * The project name is always passed, so COMPOSE_PROJECT_NAME in a .env or
     * a `name:` in the project's own compose files can't rename the stack.
     */
    protected function command(Stack $stack): array
    {
        $command = [
            'docker', 'compose',
            '--project-directory', $stack->directory(),
            '-p', $stack->name(),
        ];

        foreach ($stack->composeFiles() as $file) {
            $command[] = '-f';
            $command[] = $file;
        }

        return $command;
    }

    /**
     * Compose already reports a stopped daemon or a missing plugin clearly,
     * and checking those first would add about 160ms to every command.
     */
    protected function ensureInstalled(): void
    {
        if (! Executable::exists('docker')) {
            throw FlightException::make(
                'Docker was not found in your PATH.',
                'Install it from https://docs.docker.com/get-docker/',
            );
        }
    }

    protected function explain(ProcessResult $result, string $message): FlightException
    {
        $stderr = $result->errorOutput();

        foreach (self::HINTS as $needle => $hint) {
            if (str_contains($stderr, $needle)) {
                return FlightException::make($message, $hint);
            }
        }

        return FlightException::fromProcess($result, $message);
    }
}
