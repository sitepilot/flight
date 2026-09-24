<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Provisioning\Step;
use App\Services\PhpService;
use Illuminate\Support\Str;

class ProjectConfig extends StackConfig
{
    /**
     * The names the project file can have; flight.yaml wins when both exist.
     */
    public const array FILES = ['flight.yaml', 'flight.yml'];

    /**
     * The user's files in .flight, which survive `flight destroy`.
     */
    public const array USER_FILES = ['.env'];

    protected ?string $file = null;

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
                    'Create a flight.yaml in your project root that says what runs your app, such as `app: php:8.4`.',
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
     * Prefixed, so a project named "flight" can't collide with the global
     * stack.
     */
    public function stackName(): string
    {
        return 'flight-'.$this->name();
    }

    /**
     * Returns the user's files that were kept.
     */
    public function removeFiles(): array
    {
        $directory = $this->filesDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        $keep = array_values(array_filter(self::USER_FILES, fn (string $file): bool => file_exists("{$directory}/{$file}")));

        if ($keep === []) {
            Files::deleteDirectory($directory);

            return [];
        }

        foreach (array_diff((array) scandir($directory), ['.', '..', '.gitignore', ...$keep]) as $entry) {
            $path = "{$directory}/{$entry}";

            is_dir($path) && ! is_link($path) ? Files::deleteDirectory($path) : Files::delete($path);
        }

        return $keep;
    }

    public function environment(): array
    {
        return [
            'FLIGHT_PROJECT' => $this->name(),
            // The project's .env belongs to the application, not to compose,
            // unless the project's own compose files expect it.
            ...($this->ownComposeFiles() === [] ? ['COMPOSE_DISABLE_ENV_FILE' => '1'] : []),
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
            'services' => ['nullable', 'required_without_all:recipe,app', 'array'],
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
