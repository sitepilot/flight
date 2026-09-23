<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * Options from config.yaml or flight.yaml, filled in with defaults and
 * validated, so mistakes are reported before anything is written or
 * started.
 */
trait HasOptions
{
    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * Messages for rules that need specific wording, e.g. "webroot.regex".
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Adjust parsed YAML values before validation.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function normalize(array $options): array
    {
        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function allDefaults(): array
    {
        return $this->defaults();
    }

    /**
     * @return array<string, mixed>
     */
    protected function allRules(): array
    {
        return $this->rules();
    }

    /**
     * @return array<string, string>
     */
    protected function allMessages(): array
    {
        return $this->messages();
    }

    protected function option(string $key): mixed
    {
        return $this->options[$key];
    }

    /**
     * Fill in the defaults and validate, naming errors after their YAML
     * path, e.g. "services.mariadb.version" for the path "services.mariadb".
     * $aliases name options as written, e.g. "version" as "type".
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $aliases
     */
    protected function configure(StackConfig $config, string $path, array $options, array $aliases = []): void
    {
        $name = fn (string $key): string => $aliases[explode('.', $key)[0]] ?? $key;

        // An empty option falls back to its default.
        $options = $this->normalize([
            ...$this->allDefaults(),
            ...array_filter($options, fn ($value) => $value !== null),
        ]);

        $unknown = array_diff_key($options, $this->allDefaults());

        if ($unknown !== []) {
            throw $config->invalid(
                $this->allDefaults() === []
                    ? 'Expected no options.'
                    : 'Expected one of: '.implode(', ', array_map($name, array_keys($this->allDefaults()))).'.',
                $path.'.'.array_key_first($unknown),
            );
        }

        $keys = array_keys($this->allRules());

        $validator = Validator::make($options, $this->allRules(), [
            // The built-in message does not list the allowed values.
            'in' => 'Expected :attribute to be one of: :values.',
            ...$this->allMessages(),
        ], attributes: array_combine($keys, array_map(fn (string $key): string => "{$path}.{$name($key)}", $keys)));

        if ($validator->fails()) {
            $key = (string) array_key_first($validator->errors()->messages());

            throw $config->invalid((string) $validator->errors()->first($key), "{$path}.{$name($key)}");
        }

        $this->options = $options;
    }
}
