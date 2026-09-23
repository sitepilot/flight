<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A Valkey server, compatible with Redis, with its data in a named volume.
 * Reachable from the other services at its service name, e.g. "valkey".
 */
class Valkey extends Service
{
    public const array VERSIONS = ['7.2', '8.0', '8.1', '9.0', '9.1'];

    protected function defaults(): array
    {
        return [
            'version' => '9.1',
        ];
    }

    protected function rules(): array
    {
        return [
            'version' => ['required', 'in:'.implode(',', self::VERSIONS)],
        ];
    }

    /**
     * An unquoted `version: 8.0` parses as a float.
     */
    protected function normalize(array $options): array
    {
        if (is_float($options['version']) || is_int($options['version'])) {
            $options['version'] = sprintf('%.1f', $options['version']);
        }

        return $options;
    }

    public function definition(): array
    {
        return [
            'image' => "valkey/valkey:{$this->option('version')}",
            'restart' => 'unless-stopped',
            'volumes' => ["{$this->name}_data:/data"],
            // `flight up` waits for this, so provisioning steps can connect.
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'valkey-cli ping | grep -q PONG'],
                'interval' => '2s',
                'timeout' => '5s',
                'retries' => 30,
            ],
        ];
    }

    public function volumes(): array
    {
        return ["{$this->name}_data" => null];
    }
}
