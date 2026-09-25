<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Files;

/**
 * A PHP web server based on serversideup/php.
 *
 * The image is built rather than pulled, because serversideup/php can only
 * change the www-data user's ID at build time. Matching it to the host user
 * keeps the mounted project writable on Linux and WSL.
 */
class PhpService extends Service implements Routed
{
    public const array VERSIONS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    /**
     * The server variations, with the variable that sets their document root.
     */
    public const array SERVERS = [
        'fpm-nginx' => 'NGINX_WEBROOT',
        'fpm-apache' => 'APACHE_DOCUMENT_ROOT',
        'frankenphp' => 'CADDY_SERVER_ROOT',
    ];

    protected const string APP_DIR = '/var/www/html';

    /**
     * The pattern of a path inside the app: no leading slash and no "..".
     */
    public const string PATH = '#^(?!/)(?!.*\.\.)[A-Za-z0-9._/-]*$#';

    /**
     * A pinned release, which Docker checks against its SHA-256 from the
     * release page. Update both together.
     */
    protected const string WP_CLI = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';

    protected const string WP_CLI_SHA256 = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c';

    protected function defaults(): array
    {
        return [
            'version' => '8.4',
            'server' => 'fpm-nginx',
            'webroot' => 'public',
            'project_path' => '.',
            'extensions' => [],
            'packages' => [],
            'wp_cli' => false,
            'node' => null,
            'access_log' => false,
            'hostnames' => [],
            'workers' => [],
        ];
    }

    protected function rules(): array
    {
        return [
            'version' => ['required', 'in:'.implode(',', self::VERSIONS)],
            'server' => ['required', 'in:'.implode(',', array_keys(self::SERVERS))],
            'webroot' => ['string', 'regex:'.self::PATH],
            'project_path' => ['string', 'regex:'.self::PATH],
            'extensions' => ['list'],
            'extensions.*' => ['string', 'regex:/^[a-z0-9_]+$/'],
            'packages' => ['list'],
            'packages.*' => ['string', 'regex:/^[a-z0-9][a-z0-9.+-]*$/'],
            'wp_cli' => ['boolean'],
            'node' => ['nullable', 'string', 'regex:/^\d+(\.\d+){0,2}$/'],
            'access_log' => ['boolean'],
            'hostnames' => ['list'],
            'hostnames.*' => ['string', 'regex:'.self::LABEL],
            'workers' => ['array'],
            'workers.*' => ['string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'webroot.regex' => 'Expected a path inside the project, such as "public".',
            'project_path.regex' => 'Expected a path inside the app, such as "modules/my-module".',
            'extensions.*.regex' => 'Expected an extension name such as "mysqli".',
            'packages.*.regex' => 'Expected a Debian package name such as "git".',
            'node.string' => 'Expected a Node.js version in quotes, such as "20.10", because an unquoted 20.10 is read as 20.1.',
            'node.regex' => 'Expected a Node.js version such as "22".',
            'hostnames.*.regex' => 'Expected a lowercase subdomain such as "admin", which becomes admin.'.$this->global->domain().'.',
            'workers.array' => 'Expected workers to map names to commands, such as `queue: php artisan queue:work`.',
            'workers.*.string' => 'Expected a command, such as "php artisan queue:work".',
        ];
    }

    /**
     * An unquoted `node: 22` parses as a number. A decimal such as `20.10`
     * would lose its zero, so it has to be quoted.
     */
    protected function normalize(array $options): array
    {
        if (is_int($options['node'])) {
            $options['node'] = (string) $options['node'];
        }

        return $options;
    }

    public function definition(): array
    {
        return [
            'build' => [
                'context' => $this->buildContext(),
                'args' => [
                    'USER_ID' => (string) $this->userId(),
                    'GROUP_ID' => (string) $this->groupId(),
                ],
            ],
            // Rebuild on every up so a changed version takes effect. An
            // unchanged build is cached.
            'pull_policy' => 'build',
            'restart' => 'unless-stopped',
            'volumes' => $this->mounts(),
            // Steps and `docker exec` start in the app, wherever the project
            // is mounted.
            'working_dir' => self::APP_DIR,
            'environment' => [
                self::SERVERS[$this->option('server')] => $this->documentRoot(),
                // Serve HTTPS, so apps see an HTTPS request without having to
                // trust the proxy's forwarded headers.
                'SSL_MODE' => 'full',
                // Keep the logs to what the app writes.
                'SHOW_WELCOME_MESSAGE' => 'false',
                ...$this->logEnvironment(),
            ],
        ];
    }

    public function origin(): string
    {
        return "https://{$this->name}:8443";
    }

    public function prepare(): void
    {
        $file = basename($this->stack->config()->file());
        $install = $this->installInstructions();

        // Create the app folder and the project's mount point in it as the
        // host user. Docker would create them owned by root on Linux.
        if ($this->projectPath() !== null) {
            Files::ensureDirectory($this->directory('data').'/'.$this->projectPath());
        }

        Files::put($this->directory('build').'/Dockerfile', <<<DOCKERFILE
        # Generated by flight, do not edit. This file is overwritten on every run,
        # change {$this->path} in {$file} instead.

        FROM {$this->image()}

        USER root

        ARG USER_ID
        ARG GROUP_ID

        RUN docker-php-serversideup-set-id www-data \$USER_ID:\$GROUP_ID && \\
            docker-php-serversideup-set-file-permissions --owner \$USER_ID:\$GROUP_ID
        {$install}
        USER www-data

        DOCKERFILE);
    }

    protected function installInstructions(): string
    {
        $instructions = [];

        // WP-CLI shows its help through less.
        $packages = array_values(array_unique([
            ...$this->option('packages'),
            ...($this->option('wp_cli') ? ['less'] : []),
        ]));

        // serversideup's helper, which also cleans up after apt.
        if ($packages !== []) {
            $instructions[] = 'RUN docker-php-serversideup-dep-install-debian "'.implode(' ', $packages).'"';
        }

        if ($this->option('extensions') !== []) {
            $instructions[] = 'RUN install-php-extensions '.implode(' ', $this->option('extensions'));
        }

        if ($this->option('node') !== null) {
            $instructions[] = $this->nodeInstructions((string) $this->option('node'));
        }

        if ($this->option('wp_cli')) {
            $instructions[] = 'ADD --checksum=sha256:'.self::WP_CLI_SHA256.' --chmod=755 '.self::WP_CLI.' /usr/local/bin/wp';
        }

        // Apache's access log can't be turned off with a setting.
        if (! $this->option('access_log') && $this->option('server') === 'fpm-apache') {
            $instructions[] = "RUN find /etc/apache2 -type f \\( -name '*.conf' -o -name '*.template' \\) -exec sed -i '/^\\s*CustomLog /s/^/#/' {} +";
        }

        return implode('', array_map(fn (string $instruction): string => "\n{$instruction}\n", $instructions));
    }

    /**
     * Workers serve nothing, so skip the certificate the web server needs.
     */
    protected function workerDefinition(array $definition, string $command): array
    {
        $worker = parent::workerDefinition($definition, $command);
        $worker['environment']['SSL_MODE'] = 'off';

        return $worker;
    }

    /**
     * Node from the official image, so any version is available. Both images
     * are Debian, so the binary runs as is.
     */
    protected function nodeInstructions(string $version): string
    {
        return implode("\n", [
            "COPY --from=node:{$version}-slim /usr/local/bin/node /usr/local/bin/node",
            "COPY --from=node:{$version}-slim /usr/local/lib/node_modules /usr/local/lib/node_modules",
            'RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \\',
            '    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx',
        ]);
    }

    /**
     * Leave out a log line per request, which a page with its assets turns
     * into dozens. Warnings and PHP errors are still logged.
     */
    protected function logEnvironment(): array
    {
        if ($this->option('access_log')) {
            return [];
        }

        return match ($this->option('server')) {
            'fpm-nginx' => ['NGINX_ACCESS_LOG' => '/dev/null'],
            // Caddy logs each request at the info level.
            'frankenphp' => ['LOG_OUTPUT_LEVEL' => 'warn'],
            default => [],
        };
    }

    public function image(): string
    {
        return "serversideup/php:{$this->option('version')}-{$this->option('server')}";
    }

    /**
     * The project is the app, unless `project_path` places it inside an app
     * kept in .flight/<service>/data, e.g. a module inside a larger app.
     */
    protected function mounts(): array
    {
        if ($this->projectPath() === null) {
            return [$this->composePath().':'.self::APP_DIR];
        }

        return [
            $this->composePath('data').':'.self::APP_DIR,
            $this->composePath().':'.$this->appPath($this->projectPath()),
        ];
    }

    protected function projectPath(): ?string
    {
        $path = trim((string) $this->option('project_path'), '/');

        return $path === '' || $path === '.' ? null : $path;
    }

    protected function buildContext(): string
    {
        return $this->composePath('build');
    }

    protected function documentRoot(): string
    {
        return $this->appPath((string) $this->option('webroot'));
    }

    protected function appPath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' || $path === '.' ? self::APP_DIR : self::APP_DIR.'/'.$path;
    }

    /**
     * When running as root, www-data keeps its own ID, since 0 would run the
     * web server as root.
     */
    protected function userId(): int
    {
        $id = function_exists('posix_getuid') ? posix_getuid() : 1000;

        return $id === 0 ? 33 : $id;
    }

    protected function groupId(): int
    {
        $id = function_exists('posix_getgid') ? posix_getgid() : 1000;

        return $id === 0 ? 33 : $id;
    }
}
