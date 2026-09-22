<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Stacks\Stack;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs docker compose against a Stack.
 *
 * Everything is parameterised by the stack rather than hardcoded, which is
 * what will let a ProjectStack reuse this class untouched.
 */
class Compose
{
    /**
     * Hints for the failures worth explaining, keyed by a fragment of the
     * stderr docker produces.
     */
    protected const HINTS = [
        'Cannot connect to the Docker daemon' => 'Docker is installed but not running. Start Docker Desktop, or run: sudo systemctl start docker',
        'is not a docker command' => 'The Docker Compose plugin is missing. Install it from https://docs.docker.com/compose/install/',
    ];

    public function up(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['up', '-d'], $output);
    }

    public function down(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['down', '--remove-orphans'], $output);
    }

    public function recreate(Stack $stack, ?Closure $output = null): void
    {
        $this->run($stack, ['up', '-d', '--remove-orphans', '--force-recreate'], $output);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    public function run(Stack $stack, array $arguments, ?Closure $output = null): ProcessResult
    {
        $this->ensureInstalled();

        // The project name is pinned rather than inferred: compose still
        // auto-loads a stray .env from the project directory, and a
        // COMPOSE_PROJECT_NAME in it would otherwise rename the stack.
        $command = [
            'docker', 'compose',
            '--project-directory', $stack->directory(),
            '-p', $stack->name(),
        ];

        foreach ($stack->composeFiles() as $file) {
            $command[] = '-f';
            $command[] = $file;
        }

        $result = Process::env($stack->environment())
            ->timeout(300)
            ->run([...$command, ...$arguments], $output);

        if ($result->failed()) {
            throw $this->explain($result, 'docker compose '.implode(' ', $arguments).' failed.');
        }

        return $result;
    }

    /**
     * Only that the binary exists, which is a PATH lookup rather than a
     * process. Whether the daemon is up and the compose plugin is installed
     * is left to compose itself: probing for those cost two extra process
     * spawns (~160ms) on every command to pre-empt an error compose already
     * reports clearly in a fraction of that.
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

    /**
     * Turn docker's own stderr into one of our hints where we recognise it,
     * and pass it through verbatim otherwise.
     */
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
