<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Provisioning\Context;
use App\Provisioning\Step;
use App\Services\MariaDBService;

/**
 * WordPress on PHP and MariaDB, downloaded and installed with WP-CLI on the
 * first `flight up`.
 */
class WordPressRecipe extends Recipe
{
    protected function defaults(): array
    {
        return [
            'title' => null,
            'admin_user' => 'admin',
            'admin_password' => 'admin',
            'admin_email' => null,
        ];
    }

    protected function rules(): array
    {
        return [
            'title' => ['nullable', 'string'],
            'admin_user' => ['required', 'string'],
            'admin_password' => ['required', 'string'],
            'admin_email' => ['nullable', 'email'],
        ];
    }

    public function app(): array
    {
        return [
            'type' => 'php',
            'webroot' => '.',
            'extensions' => ['mysqli', 'gd', 'exif', 'intl'],
            'packages' => ['mariadb-client'],
            'wp_cli' => true,
        ];
    }

    public function services(): array
    {
        return [
            'mariadb' => [
                'type' => 'mariadb',
                'database' => 'wordpress',
                'user' => 'wordpress',
                'password' => 'wordpress',
            ],
        ];
    }

    public function provision(Context $context): array
    {
        /** @var MariaDBService $database */
        $database = $context->service('mariadb');

        return [
            Step::make('Download WordPress')
                ->run('wp core download')
                ->unless('test -f wp-load.php'),

            Step::make('Configure WordPress')
                ->run($this->command('wp config create', [
                    'dbhost' => $database->name(),
                    'dbname' => $database->database(),
                    'dbuser' => $database->user(),
                    'dbpass' => $database->password(),
                ]))
                ->unless('test -f wp-config.php'),

            Step::make('Install WordPress')
                ->run($this->command('wp core install --skip-email', [
                    'url' => $context->url(),
                    'title' => $this->option('title') ?? $context->project(),
                    'admin_user' => $this->option('admin_user'),
                    'admin_password' => $this->option('admin_password'),
                    'admin_email' => $this->option('admin_email') ?? 'admin@'.$context->domain(),
                ]))
                ->unless('wp core is-installed'),
        ];
    }

    protected function command(string $command, array $flags): string
    {
        foreach ($flags as $flag => $value) {
            $command .= " --{$flag}=".escapeshellarg($value);
        }

        return $command;
    }
}
