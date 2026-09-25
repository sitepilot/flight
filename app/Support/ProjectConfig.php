<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Provisioning\Step;
use App\Services\PhpService;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ProjectConfig extends StackConfig
{
    /**
     * The names the project file can have; flight.yaml wins when both exist.
     */
    public const array FILES = ['flight.yaml', 'flight.yml'];

    protected ?string $file = null;

    protected ?string $stackName = null;

    /**
     * Searched for up from the current directory, so commands work anywhere
     * inside the project.
     */
    public function file(): string
    {
        if ($this->file !== null) {
            return $this->file;
        }

        $directory = (string) getcwd();

        while (true) {
            foreach (self::FILES as $name) {
                if (is_file($directory.'/'.$name)) {
                    return $this->file = $directory.'/'.$name;
                }
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                throw FlightException::make(
                    'No flight.yaml found in '.getcwd().' or any parent directory.',
                    'Create a flight.yaml in your project root that says what runs your app, such as `app: php:8.4`, or `compose: [compose.yml]` for your own compose files.',
                );
            }

            $directory = $parent;
        }
    }

    public function root(): string
    {
        return dirname($this->file());
    }

    public function directory(): string
    {
        return $this->root();
    }

    public function name(): string
    {
        return $this->load()['name'];
    }

    /**
     * A project that runs from its own compose files, with services from
     * `x-flight`, gets the name docker compose itself gives it, so plain
     * `docker compose` commands act on the same containers and volumes.
     * Others are prefixed, so they can't collide with a compose project of
     * the same folder name.
     */
    public function stackName(): string
    {
        if ($this->stackName !== null) {
            return $this->stackName;
        }

        $this->load();

        if ($this->extensions === []) {
            return $this->stackName = 'flight-'.$this->name();
        }

        // Compose's own rules: COMPOSE_PROJECT_NAME, a `name:` in the files,
        // or the folder of the first one.
        $name = (string) ($this->composeModel($this->load())['name'] ?? '');

        if ($name === $this->container->make(GlobalConfig::class)->stackName()) {
            throw $this->invalid("Expected another project name; \"{$name}\" is the name of the Flight stack.", 'name');
        }

        return $this->stackName = $name;
    }

    /**
     * The Flight name of the project at $root, without loading all of its
     * settings.
     */
    public static function nameAt(string $root): string
    {
        foreach (self::FILES as $file) {
            if (is_file("{$root}/{$file}")) {
                try {
                    $name = Yaml::parseFile("{$root}/{$file}")['name'] ?? null;
                } catch (Throwable) {
                    $name = null;
                }

                return is_string($name) ? $name : Str::slug(basename($root));
            }
        }

        return Str::slug(basename($root));
    }

    public function removeFiles(): void
    {
        if (is_dir($this->filesDirectory())) {
            Files::deleteDirectory($this->filesDirectory());
        }
    }

    protected function variables(array $settings): array
    {
        return [
            ...$this->container->make(GlobalConfig::class)->environment(),
            'FLIGHT_PROJECT' => $settings['name'],
        ];
    }

    /**
     * The app is served at <project>.<domain>, any other service at
     * <project>-<service>.<domain>: one level under the domain, which is all
     * the wildcard certificate covers.
     */
    public function label(string $service): string
    {
        return $service === self::APP ? $this->name() : $this->name().'-'.$service;
    }

    public function provision(): array
    {
        return array_map(Step::fromArray(...), $this->load()['provision'] ?? []);
    }

    protected function recipeSetting(array $settings): mixed
    {
        return $settings['recipe'] ?? null;
    }

    protected function withDefaults(array $settings): array
    {
        $settings['name'] ??= Str::slug(basename($this->root()));

        return $settings;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'app' => ['nullable'],
            'recipe' => ['nullable'],
            'services' => ['nullable', 'required_without_all:recipe,app,compose', 'array'],
            'provision' => ['nullable', 'list'],
            'provision.*' => ['array:name,service,run,unless,dir,env'],
            'provision.*.name' => ['required', 'string'],
            'provision.*.service' => ['nullable', 'string'],
            'provision.*.run' => ['required', 'string'],
            'provision.*.unless' => ['nullable', 'string'],
            'provision.*.dir' => ['nullable', 'string', 'regex:'.PhpService::PATH],
            'provision.*.env' => ['nullable', 'list'],
            'provision.*.env.*' => ['string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Expected name to be set, or the directory name to contain a letter or digit.',
            'name.regex' => 'Expected a lowercase name such as "myapp"; it becomes myapp.<domain>.',
            'services.required_without_all' => 'Expected an app such as `app: php:8.4`, services, or a recipe such as "laravel".',
            'provision.list' => 'Expected provision to be a list of steps.',
            'provision.*.array' => 'Expected a step with name, run and optionally service, unless, dir and env.',
            'provision.*.env.list' => 'Expected a list of variable names, such as [COMPOSER_AUTH].',
            'provision.*.env.*.regex' => 'Expected a variable name, such as "COMPOSER_AUTH".',
            'provision.*.dir.regex' => 'Expected a path inside the app, such as "assets".',
        ];
    }
}
