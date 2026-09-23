<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The settings in a project's flight.yml. Only the top level is validated
 * here; each service validates its own options.
 */
class ProjectConfig
{
    public const string FILE = 'flight.yml';

    protected ?string $root = null;

    /** @var array{name: string, services: array<string, array<string, mixed>>}|null */
    protected ?array $settings = null;

    /**
     * The directory containing flight.yml, found by searching up from the
     * current directory, so commands work anywhere inside the project.
     */
    public function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $directory = (string) getcwd();

        while (! is_file($directory.'/'.self::FILE)) {
            $parent = dirname($directory);

            if ($parent === $directory) {
                throw FlightException::make(
                    'No '.self::FILE.' found in '.getcwd().' or any parent directory.',
                    'Create a '.self::FILE.' in your project root that lists the services it needs.',
                );
            }

            $directory = $parent;
        }

        return $this->root = $directory;
    }

    public function file(): string
    {
        return $this->root().'/'.self::FILE;
    }

    /**
     * Where the project's generated files are written.
     */
    public function directory(): string
    {
        return $this->root().'/.flight';
    }

    public function composeFile(): string
    {
        return $this->directory().'/compose.yaml';
    }

    public function overrideFile(): string
    {
        return $this->directory().'/compose.override.yaml';
    }

    public function name(): string
    {
        return $this->load()['name'];
    }

    /**
     * Options by service name, each with its type set.
     *
     * @return array<string, array<string, mixed>>
     */
    public function services(): array
    {
        return $this->load()['services'];
    }

    /**
     * Read and validate flight.yml. Parsed once per run.
     *
     * @return array{name: string, services: array<string, array<string, mixed>>}
     */
    public function load(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        try {
            $parsed = Yaml::parseFile($this->file());
        } catch (ParseException $e) {
            throw FlightException::make('Could not parse '.$this->file().'.', $e->getMessage());
        }

        if ($parsed !== null && ! is_array($parsed)) {
            throw $this->invalid('Expected a mapping of settings.');
        }

        $parsed = (array) $parsed;

        $parsed['name'] ??= Str::slug(basename($this->root()));

        $this->validate($parsed);

        $services = [];

        // A bare `php:` is null and means "use the defaults".
        foreach ($parsed['services'] as $name => $options) {
            $services[$name] = ['type' => $name, ...(array) $options];
        }

        return $this->settings = [
            'name' => $parsed['name'],
            'services' => $services,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function validate(array $settings): void
    {
        $validator = Validator::make($settings, [
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'services' => ['required', 'array'],
        ], [
            'name.required' => 'Expected name to be set, or the directory name to contain a letter or digit.',
            'name.string' => 'Expected name to be text.',
            'name.regex' => 'Expected a lowercase name such as "myapp"; it becomes myapp.<domain>.',
            'services.required' => 'Expected services to list at least one service, such as `php: {}`.',
            'services.array' => 'Expected services to be a mapping, such as `php: {}`.',
        ]);

        if ($validator->fails()) {
            $key = array_key_first($validator->errors()->messages());

            throw $this->invalid((string) $validator->errors()->first($key), $key);
        }

        if (array_is_list($settings['services'])) {
            throw $this->invalid('Expected services to be a mapping, such as `php: {}`.', 'services');
        }

        foreach ($settings['services'] as $name => $options) {
            if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string) $name)) {
                throw $this->invalid('Expected a lowercase service name such as "php".', "services.{$name}");
            }

            if ($options !== null && (! is_array($options) || ($options !== [] && array_is_list($options)))) {
                throw $this->invalid('Expected a mapping of options, such as `version: "8.4"`.', "services.{$name}");
            }
        }
    }

    /**
     * An error that names the key to fix.
     */
    public function invalid(string $hint, ?string $key = null): FlightException
    {
        return FlightException::make(
            $key === null ? 'Invalid '.$this->file().'.' : "Invalid \"{$key}\" in ".$this->file().'.',
            $hint,
        );
    }
}
