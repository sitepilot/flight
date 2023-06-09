<?php

namespace App\Repositories;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

class ConfigRepository
{
    protected ?string $file = null;

    protected ?array $config = null;

    public function file(): string
    {
        if ($this->file) {
            return $this->file;
        }

        $path = getcwd();
        for ($i = 0; $i < 4; $i++) {
            $file = $path . DIRECTORY_SEPARATOR . 'flight.yml';
            if (File::isFile($file)) {
                $this->file = realpath($file);
            } else {
                $path .= DIRECTORY_SEPARATOR . '..';
            }
        }

        if (!$this->file) {
            $this->abort("Could not find a flight configuration file.");
        }

        return $this->file;
    }

    public function path(string $path = ''): string
    {
        return dirname($this->file()) . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    public function name(): string
    {
        return basename($this->path());
    }

    public function id(): string
    {
        return Str::slug('fl-' . $this->name());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(
            $this->loadConfig(),
            $key,
            $default
        );
    }

    public function all(): array
    {
        return $this->loadConfig();
    }

    public function abort(string $message, int $code = 1): void
    {
        abort($code, $message);
    }

    public function validate(array $rules, array $messages = []): void
    {
        $config = $this->loadConfig();

        try {
            Validator::make($config, $rules, $messages)->validate();
        } catch (ValidationException $e) {
            $this->abort($e->getMessage());
        }
    }

    private function loadConfig(): array
    {
        if ($this->config) {
            return $this->config;
        }

        return $this->config = Yaml::parse(File::get($this->file())) ?: [];
    }
}
