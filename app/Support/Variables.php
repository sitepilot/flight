<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Provisioning\Step;
use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use Illuminate\Support\Str;

/**
 * Variables that aren't committed, such as license keys. They're looked up in
 * the shell, then the project's .flight/.env, then ~/.config/flight/.env.
 */
class Variables
{
    protected array $files = [];

    public function __construct(protected GlobalConfig $global) {}

    public function get(StackConfig $config, string $name): ?string
    {
        $value = getenv($name);

        if ($value !== false) {
            return $value;
        }

        foreach ($this->sources($config) as $file) {
            $value = $this->read($file)[$name] ?? null;

            if ($value !== null) {
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

    protected function sources(StackConfig $config): array
    {
        return [
            $config->filesDirectory().'/.env',
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
