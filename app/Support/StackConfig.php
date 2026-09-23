<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use App\Recipes\Recipe;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A YAML file that describes a stack: a few top-level settings, an app and
 * services, merged over a recipe. The app becomes the service `app`. Only the
 * shape of each service is checked here; each service validates its own
 * options.
 */
abstract class StackConfig
{
    /**
     * The `app` setting, and the service it becomes.
     */
    public const string APP = 'app';

    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    protected ?Recipe $recipe = null;

    public function __construct(protected Container $container) {}

    abstract public function file(): string;

    /**
     * The compose project name, e.g. "flight".
     */
    abstract public function stackName(): string;

    /**
     * The compose --project-directory. Relative paths in the compose file
     * resolve against it.
     */
    abstract public function directory(): string;

    /**
     * Where the stack's generated files are written.
     */
    abstract public function filesDirectory(): string;

    /**
     * The hostname label of a routed service.
     */
    abstract public function label(string $service): string;

    /**
     * Rules for the top-level keys other than services.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * The recipe as written: a name, a mapping of one name to its options,
     * or null.
     *
     * @param  array<string, mixed>  $settings
     */
    abstract protected function recipeSetting(array $settings): mixed;

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function withDefaults(array $settings): array
    {
        return $settings;
    }

    public function composeFile(): string
    {
        return $this->filesDirectory().'/compose.yaml';
    }

    public function overrideFile(): string
    {
        return $this->filesDirectory().'/compose.override.yaml';
    }

    /**
     * What the reader of the generated compose file should edit instead.
     */
    public function composeNote(): string
    {
        return 'add your own services to compose.override.yaml instead.';
    }

    /**
     * Create what the stack needs on disk before its compose file is
     * written.
     */
    public function prepare(): void {}

    /**
     * Variables passed to compose, so override files can use them.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }

    /**
     * Whether this stack creates the shared network. The other stacks join
     * it as an external network.
     */
    public function ownsNetwork(): bool
    {
        return false;
    }

    public function recipe(): ?Recipe
    {
        $this->load();

        return $this->recipe;
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

    protected function get(string $key): mixed
    {
        return $this->load()[$key] ?? null;
    }

    /**
     * Read and validate the file, merged over its recipe. Parsed once per
     * run.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $settings = $this->withDefaults($this->read());

        $this->validate($settings);

        $this->recipe = $this->makeRecipe($this->recipeSetting($settings));

        // A string is short for its type: `db: mariadb:11.8`.
        $shorthand = fn (mixed $entry): mixed => is_string($entry) ? ['type' => $entry] : $entry;

        $services = array_map($shorthand, (array) ($settings['services'] ?? []));
        $file = $shorthand($settings[self::APP] ?? null);

        foreach ($services as $name => $options) {
            $this->ensureRecipeType("services.{$name}", $this->recipe?->services()[$name] ?? null, $options);
        }

        $this->ensureRecipeType(self::APP, $this->recipe?->app(), $file);

        $merged = $this->merge($this->recipe?->services() ?? [], $services);

        if (array_key_exists(self::APP, $merged)) {
            throw $this->invalid('Expected another name; "app" is your app, set at the top level as `app:`.', 'services.'.self::APP);
        }

        if ($file !== null && ! $this->isMap($file)) {
            throw $this->invalid('Expected app to be a type such as `php:8.4`, or a mapping with a type.', self::APP);
        }

        if ($this->recipe?->app() !== null || $file !== null) {
            $merged = [self::APP => $this->merge($this->recipe?->app() ?? [], (array) $file), ...$merged];
        }

        $services = [];

        foreach ($merged as $name => $options) {
            $services[$name] = $this->service((string) $name, $options);
        }

        return $this->settings = [...$settings, 'services' => $services];
    }

    /**
     * Keep the recipe's type when the file changes it, so the recipe's options
     * still fit: `php:8.5` over the recipe's `php` is fine, `mariadb` is not.
     */
    protected function ensureRecipeType(string $path, mixed $recipe, mixed $file): void
    {
        $base = fn (mixed $options): ?string => is_string($options['type'] ?? null)
            ? explode(':', $options['type'])[0]
            : null;

        $expected = is_array($recipe) ? $base($recipe) : null;
        $actual = is_array($file) ? $base($file) : null;

        if ($expected !== null && $actual !== null && $expected !== $actual) {
            throw $this->invalid("Expected {$expected}, as set by the {$this->recipe?->name()} recipe; you can change its version, such as `{$expected}:<version>`.", "{$path}.type");
        }
    }

    /**
     * A service's options, with its type split from its version: `type:
     * mariadb:11.8` becomes the type "mariadb" and the version "11.8". The
     * type is required, from flight.yaml or the recipe.
     *
     * @return array<string, mixed>
     */
    protected function service(string $name, mixed $options): array
    {
        $app = $name === self::APP;
        $path = $app ? self::APP : "services.{$name}";
        $example = $app ? 'php:8.4' : 'mariadb:11.8';

        if (! $app && ! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw $this->invalid('Expected a lowercase service name such as "db".', $path);
        }

        if (! $this->isMap($options)) {
            throw $this->invalid("Expected a type such as `{$example}`, or a mapping with a type.", $path);
        }

        if (array_key_exists('version', $options)) {
            throw $this->invalid("Expected the version in the type, such as `type: {$example}`.", "{$path}.version");
        }

        // Only some types can run an app.
        $types = $app ? (array) config('flight.app_types') : array_keys((array) config('flight.services'));
        $type = $options['type'] ?? null;

        if (! is_string($type) || ! preg_match('/^([a-z0-9_-]+)(?::([A-Za-z0-9._-]+))?$/', $type, $match) || ! in_array($match[1], $types, true)) {
            throw $this->invalid("Expected a type such as `{$example}`, one of: ".implode(', ', $types).'.', "{$path}.type");
        }

        unset($options['type']);

        return ['type' => $match[1], ...(isset($match[2]) ? ['version' => $match[2]] : []), ...$options];
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        if (! is_file($this->file())) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($this->file());
        } catch (ParseException $e) {
            throw FlightException::make('Could not parse '.$this->file().'.', $e->getMessage());
        }

        if ($parsed !== null && ! is_array($parsed)) {
            throw $this->invalid('Expected a mapping of settings.');
        }

        return (array) $parsed;
    }

    protected function makeRecipe(mixed $setting): ?Recipe
    {
        if ($setting === null) {
            return null;
        }

        if (is_string($setting)) {
            [$name, $options] = [$setting, []];
        } elseif ($this->isMap($setting) && count($setting) === 1) {
            $name = (string) array_key_first($setting);
            $options = $setting[$name] ?? [];
        } else {
            throw $this->invalid('Expected a recipe such as "laravel", or one recipe with its options, such as `laravel: {}`.', 'recipe');
        }

        $recipes = (array) config('flight.recipes');

        if (! isset($recipes[$name])) {
            throw $this->invalid('Expected recipe to be one of: '.implode(', ', array_keys($recipes)).'.', 'recipe');
        }

        if (! $this->isMap($options)) {
            throw $this->invalid('Expected a mapping of options.', "recipe.{$name}");
        }

        return $this->container->make($recipes[$name], ['config' => $this, 'name' => $name, 'options' => $options]);
    }

    /**
     * Merge the file's services over the recipe's, option by option. Maps
     * merge key by key, anything else is replaced. A null option, such as a
     * bare `php:`, keeps the recipe's value.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $override
     * @return array<array-key, mixed>
     */
    protected function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;

            if ($value === null && array_key_exists($key, $base)) {
                continue;
            }

            $base[$key] = $this->isMap($current) && $this->isMap($value)
                ? $this->merge($current, $value)
                : $value;
        }

        return $base;
    }

    protected function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function validate(array $settings): void
    {
        $rules = ['services' => ['nullable', 'array'], ...$this->rules()];

        $topLevel = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));

        foreach (array_keys($settings) as $key) {
            if (! in_array($key, $topLevel, true)) {
                throw $this->invalid('Expected one of: '.implode(', ', $topLevel).'.', (string) $key);
            }
        }

        // Name attributes after the YAML keys, e.g. "http_port" instead of
        // "http port". Wildcard keys already show their path.
        $keys = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '*'));

        $validator = Validator::make($settings, $rules, [
            'services.array' => 'Expected services to be a mapping of names to types, such as `db: mariadb:11.8`.',
            ...$this->messages(),
        ], attributes: array_combine($keys, $keys));

        if ($validator->fails()) {
            // Report one problem at a time, naming the key to fix.
            $key = (string) array_key_first($validator->errors()->messages());

            throw $this->invalid((string) $validator->errors()->first($key), $key);
        }

        $services = $settings['services'] ?? [];

        if ($services !== [] && array_is_list($services)) {
            throw $this->invalid('Expected services to be a mapping of names to types, such as `db: mariadb:11.8`.', 'services');
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
