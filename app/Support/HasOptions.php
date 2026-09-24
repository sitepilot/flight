<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * Options from config.yaml or flight.yaml, filled in with defaults and
 * validated.
 */
trait HasOptions
{
    protected array $options = [];

    protected function defaults(): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function messages(): array
    {
        return [];
    }

    protected function normalize(array $options): array
    {
        return $options;
    }

    protected function allMessages(): array
    {
        return $this->messages();
    }

    protected function option(string $key): mixed
    {
        return $this->options[$key];
    }

    /**
     * Errors are named after their path in the YAML, e.g.
     * "services.mariadb.version". $aliases name options as written, e.g.
     * "version" as "type".
     */
    protected function configure(StackConfig $config, string $path, array $options, array $aliases = []): void
    {
        $name = fn (string $key): string => $aliases[explode('.', $key)[0]] ?? $key;

        // An empty option falls back to its default.
        $options = $this->normalize([
            ...$this->defaults(),
            ...array_filter($options, fn ($value) => $value !== null),
        ]);

        $unknown = array_diff_key($options, $this->defaults());

        if ($unknown !== []) {
            throw $config->invalid(
                $this->defaults() === []
                    ? 'Expected no options.'
                    : 'Expected one of: '.implode(', ', array_map($name, array_keys($this->defaults()))).'.',
                $path.'.'.array_key_first($unknown),
            );
        }

        $keys = array_keys($this->rules());

        $validator = Validator::make($options, $this->rules(), [
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
