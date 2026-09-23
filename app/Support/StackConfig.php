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
 * A YAML file that describes a stack: a few top-level settings and a
 * services section merged over a recipe. Only the shape of each service is
 * checked here; each service validates its own options.
 */
abstract class StackConfig
{
    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

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
     * The hostname label of a routed service. $routed counts the routed
     * services before it.
     */
    abstract public function label(string $service, int $routed): string;

    /**
     * Rules for the top-level keys other than services.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @param  array<string, mixed>  $settings
     */
    abstract protected function recipeName(array $settings): ?string;

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

    public function recipe(): ?string
    {
        return $this->load()['recipe'];
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

        $recipe = $this->recipeName($settings);

        $merged = $this->merge(
            $recipe === null ? [] : $this->makeRecipe($recipe)->services(),
            (array) ($settings['services'] ?? []),
        );

        $this->validateServices($merged);

        $services = [];

        // A bare `php:` is null and means "use the defaults".
        foreach ($merged as $name => $options) {
            $services[$name] = ['type' => $name, ...(array) $options];
        }

        return $this->settings = [...$settings, 'recipe' => $recipe, 'services' => $services];
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

    protected function makeRecipe(string $name): Recipe
    {
        return $this->container->make(config('flight.recipes')[$name]);
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

        foreach (array_keys($settings) as $key) {
            if (! isset($rules[$key])) {
                throw $this->invalid('Expected one of: '.implode(', ', array_keys($rules)).'.', (string) $key);
            }
        }

        // Name attributes after the YAML keys, e.g. "http_port" instead of
        // "http port".
        $keys = array_keys($rules);

        $validator = Validator::make($settings, $rules, [
            'services.array' => 'Expected services to be a mapping, such as `php: {}`.',
            ...$this->messages(),
        ], attributes: array_combine($keys, $keys));

        if ($validator->fails()) {
            // Report one problem at a time, naming the key to fix.
            $key = (string) array_key_first($validator->errors()->messages());

            throw $this->invalid((string) $validator->errors()->first($key), $key);
        }

        $services = $settings['services'] ?? [];

        if ($services !== [] && array_is_list($services)) {
            throw $this->invalid('Expected services to be a mapping, such as `php: {}`.', 'services');
        }
    }

    /**
     * Check the merged services, so the recipe's are checked too.
     *
     * @param  array<array-key, mixed>  $services
     */
    protected function validateServices(array $services): void
    {
        foreach ($services as $name => $options) {
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
