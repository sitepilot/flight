<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Provisioning\Step;
use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Variables that aren't committed, such as license keys. They're looked up in
 * the shell, then the project's .env, then ~/.config/flight/.env.
 */
class Variables
{
    protected array $files = [];

    /**
     * Whether a variable was read from the project's .env.
     */
    protected bool $readProjectFile = false;

    public function __construct(protected GlobalConfig $global) {}

    public function get(StackConfig $config, string $name): ?string
    {
        $value = getenv($name);

        if ($value !== false) {
            return $value;
        }

        foreach ($this->sources($config) as $i => $file) {
            $value = $this->read($file)[$name] ?? null;

            if ($value !== null) {
                $this->readProjectFile = $this->readProjectFile || $i === 0;

                return $value;
            }
        }

        return null;
    }

    /**
     * A variable that's set nowhere is an error, reported before anything
     * starts.
     */
    public function forStep(StackConfig $config, Step $step): array
    {
        $values = [];

        foreach ($step->env as $name) {
            $values[$name] = $this->get($config, $name)
                ?? throw FlightException::make("Step \"{$step->name}\" needs {$name}.", $this->hint($config));
        }

        return $values;
    }

    public function hint(StackConfig $config): string
    {
        $files = array_map(
            fn (string $file): string => Str::replaceStart($_SERVER['HOME'] ?? '', '~', $file),
            $this->sources($config),
        );

        return 'Set it in '.implode(', in ', array_unique($files)).', or in your shell.';
    }

    /**
     * The project's .env, when a variable was read from it and Git doesn't
     * ignore it, so the secret could be committed.
     */
    public function unignoredProjectFile(StackConfig $config): ?string
    {
        $directory = $config->projectDirectory();

        if (! $this->readProjectFile || ! Executable::exists('git')) {
            return null;
        }

        // Exits with 1 when the file isn't ignored, and 128 outside a
        // repository, where nothing is committed.
        $ignored = Process::run(['git', '-C', $directory, 'check-ignore', '--quiet', '.env'])->exitCode();

        return $ignored === 1 ? $directory.'/.env' : null;
    }

    protected function sources(StackConfig $config): array
    {
        return [
            $config->projectDirectory().'/.env',
            $this->global->directory().'/.env',
        ];
    }

    protected function read(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        try {
            return $this->files[$file] ??= Dotenv::parse((string) file_get_contents($file));
        } catch (ExceptionInterface $e) {
            throw FlightException::make("Could not parse {$file}.", $e->getMessage());
        }
    }
}
