<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Stacks\Stack;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs docker compose for a stack, writing its compose file first so it is
 * never stale.
 */
class Compose
{
    public function __construct(protected Scaffold $scaffold) {}

    /**
     * Hints for common failures, keyed by text that appears in stderr.
     */
    protected const array HINTS = [
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

        $this->scaffold->write($stack);

        // Always pass the project name, so COMPOSE_PROJECT_NAME in a stray
        // .env can't rename the stack.
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
     * Only check that the binary is on PATH. Compose already reports a
     * stopped daemon or a missing plugin clearly, and checking those first
     * would add about 160ms to every command.
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
     * Replace docker's stderr with a hint when we recognize it.
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
