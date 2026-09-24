<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A MariaDB server with its data in a named volume. Reachable from the
 * other services at its name, e.g. "db".
 */
class MariaDBService extends Service
{
    public const array VERSIONS = ['10.6', '10.11', '11.4', '11.8'];

    protected function defaults(): array
    {
        return [
            'version' => '11.8',
            'database' => 'flight',
            'user' => 'flight',
            'password' => 'flight',
        ];
    }

    protected function rules(): array
    {
        return [
            'version' => ['required', 'in:'.implode(',', self::VERSIONS)],
            'database' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
            'user' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'database.regex' => 'Expected letters, digits and underscores, such as "shop".',
            'user.regex' => 'Expected letters, digits and underscores, such as "shop".',
        ];
    }

    public function database(): string
    {
        return $this->option('database');
    }

    public function user(): string
    {
        return $this->option('user');
    }

    public function password(): string
    {
        return $this->option('password');
    }

    public function description(): string
    {
        return "MariaDB {$this->option('version')} at {$this->name}:3306";
    }

    public function definition(): array
    {
        return [
            'image' => "mariadb:{$this->option('version')}",
            'restart' => 'unless-stopped',
            'environment' => [
                'MARIADB_DATABASE' => $this->database(),
                'MARIADB_USER' => $this->user(),
                'MARIADB_PASSWORD' => $this->password(),
                'MARIADB_ROOT_PASSWORD' => $this->password(),
            ],
            'volumes' => ["{$this->name}_data:/var/lib/mysql"],
            // `flight up` waits for this, so provisioning steps can connect.
            'healthcheck' => [
                'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
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
