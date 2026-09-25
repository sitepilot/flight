<?php

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    flightDirectory();
});

/**
 * A flight.yaml with a PHP app; a "version" goes into its type.
 */
function phpApp(array $options = [], array $settings = []): array
{
    $type = isset($options['version']) ? "php:{$options['version']}" : 'php';
    unset($options['version']);

    return [...$settings, 'app' => ['type' => $type, ...$options]];
}

function invalidPhp(array $options): string
{
    flightProject(phpApp($options));

    try {
        writeProjectCompose();
    } catch (FlightException $e) {
        return $e->getMessage().' '.$e->hint();
    }

    throw new RuntimeException('Expected the options to be rejected.');
}

it('runs a php app, built from serversideup/php', function () {
    flightProject();

    $compose = writeProjectCompose();
    $app = $compose['services']['app'];

    expect($compose['name'])->toBe('flight-myapp')
        ->and($app['build']['context'])->toBe('./.flight/app/build')
        ->and($app['build']['args'])->toHaveKeys(['USER_ID', 'GROUP_ID'])
        ->and($app['pull_policy'])->toBe('build')
        ->and($app['volumes'])->toBe(['.:/var/www/html'])
        ->and($app['networks'])->toBe(['default', 'flight'])
        // Served over HTTPS, so apps see an HTTPS request.
        ->and($app['environment'])->toBe(['NGINX_WEBROOT' => '/var/www/html/public', 'SSL_MODE' => 'full', 'SHOW_WELCOME_MESSAGE' => 'false', 'NGINX_ACCESS_LOG' => '/dev/null'])
        ->and($app['labels'])->toBe([
            'traefik.enable' => 'true',
            'traefik.http.routers.flight-myapp-app.rule' => 'Host(`myapp.flght.dev`)',
            'traefik.http.services.flight-myapp-app.loadbalancer.server.port' => '8443',
            'traefik.http.services.flight-myapp-app.loadbalancer.server.scheme' => 'https',
        ])
        ->and($compose['networks']['flight'])->toBe(['name' => 'flight', 'external' => true]);
});

it('reads the version from the type', function (string $type, string $image) {
    flightProject(['app' => ['type' => $type]]);

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'))->toContain("FROM serversideup/php:{$image}");
})->with([
    'with a version' => ['php:8.3', '8.3-fpm-nginx'],
    'without one' => ['php', '8.4-fpm-nginx'],
]);

it('rejects an app without a valid type', function (mixed $app, string $key) {
    flightProject(['app' => $app]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, "Invalid \"{$key}\"");
})->with([
    'a string with an unknown type' => ['node:22', 'app.type'],
    'a list' => [['php'], 'app'],
    'no type' => [['server' => 'fpm-nginx'], 'app.type'],
    'an unknown type' => [['type' => 'ruby:3.3'], 'app.type'],
    // A database can't run an app.
    'a service type' => [['type' => 'mariadb'], 'app.type'],
]);

it('rejects a version apart from the type', function () {
    flightProject(['app' => ['type' => 'php', 'version' => '8.3']]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, 'Invalid "app.version"');
});

it('reserves the name app for the app', function () {
    flightProject(['services' => ['app' => ['type' => 'php']]]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, 'Invalid "services.app"');
});

it('builds with the host user id so the project stays writable', function () {
    flightProject();

    $args = writeProjectCompose()['services']['app']['build']['args'];

    $uid = posix_getuid() === 0 ? 33 : posix_getuid();

    expect($args['USER_ID'])->toBe((string) $uid);
});

it('writes a dockerfile for the chosen version and server', function () {
    flightProject(phpApp(['version' => '8.3', 'server' => 'frankenphp']));

    $compose = writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/app/build/Dockerfile');

    expect($dockerfile)->toContain('FROM serversideup/php:8.3-frankenphp')
        ->and($dockerfile)->toContain('docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID')
        ->and($dockerfile)->toContain('USER www-data')
        ->and($compose['services']['app']['environment'])->toHaveKey('CADDY_SERVER_ROOT');
});

it('serves the project root when the webroot is empty', function () {
    flightProject(phpApp(['webroot' => '.', 'server' => 'fpm-apache']));

    expect(writeProjectCompose()['services']['app']['environment']['APACHE_DOCUMENT_ROOT'])->toBe('/var/www/html');
});

it('follows the configured domain and network', function () {
    flightConfig(['domain' => 'test.dev', 'network' => 'proxy']);
    flightProject(phpApp(settings: ['name' => 'shop']));

    $compose = writeProjectCompose();

    expect($compose['services']['app']['labels']['traefik.http.routers.flight-shop-app.rule'])->toBe('Host(`shop.test.dev`)')
        ->and($compose['networks']['flight']['name'])->toBe('proxy');
});

it('names the offending key as written when an option is invalid', function (array $options, string $key) {
    expect(invalidPhp($options))->toContain("Invalid \"app.{$key}\"");
})->with([
    'unsupported version' => [['version' => '7.0'], 'type'],
    'unknown server' => [['server' => 'nginx'], 'server'],
    'webroot outside the project' => [['webroot' => '../elsewhere'], 'webroot'],
    'absolute webroot' => [['webroot' => '/srv'], 'webroot'],
]);

it('lists the supported versions', function () {
    expect(invalidPhp(['version' => '7.0']))
        ->toContain('Expected a php version, one of: 8.1, 8.2, 8.3, 8.4, 8.5.');
});

it('rejects an unknown option', function () {
    expect(invalidPhp(['verison' => '8.3']))
        ->toContain('Invalid "app.verison"')
        ->toContain('Expected one of: type, server, webroot, project_path, extensions, packages, wp_cli, node, access_log, hostnames, workers.');
});

it('rejects an unknown service type', function () {
    flightProject(['services' => ['cache' => ['type' => 'redis']]]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, 'Invalid "services.cache.type"');
});

it('requires a type for every service', function (mixed $service) {
    flightProject(['services' => ['db' => $service]]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, 'Invalid "services.db');
})->with([
    'nothing' => [null],
    'options only' => [['database' => 'shop']],
]);

it('keeps generated files out of git', function () {
    flightProject();

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/.gitignore'))->toBe("# Generated by flight.\n*\n");
});

it('serves an extra php service beside the app', function () {
    flightProject(phpApp(settings: ['services' => ['legacy' => ['type' => 'php:8.1']]]));

    $services = writeProjectCompose()['services'];

    expect($services['app']['labels']['traefik.http.routers.flight-myapp-app.rule'])->toBe('Host(`myapp.flght.dev`)')
        ->and($services['legacy']['labels']['traefik.http.routers.flight-myapp-legacy.rule'])->toBe('Host(`myapp-legacy.flght.dev`)')
        ->and($services['legacy']['networks'])->toBe(['default', 'flight']);
});

it('gives a php service without an app an address of its own', function () {
    flightProject(['services' => ['php' => ['type' => 'php']]]);

    expect(writeProjectCompose()['services']['php']['labels']['traefik.http.routers.flight-myapp-php.rule'])
        ->toBe('Host(`myapp-php.flght.dev`)');
});

it('adds extra hostnames alongside the assigned one', function () {
    flightProject(phpApp(['hostnames' => ['shop', 'api']]));

    expect(writeProjectCompose()['services']['app']['labels']['traefik.http.routers.flight-myapp-app.rule'])
        ->toBe('Host(`myapp.flght.dev`) || Host(`shop.flght.dev`) || Host(`api.flght.dev`)');
});

it('accepts a single extra hostname without a list', function () {
    flightProject("app:\n  type: php\n  hostnames: shop\n");

    expect(writeProjectCompose()['services']['app']['labels']['traefik.http.routers.flight-myapp-app.rule'])
        ->toBe('Host(`myapp.flght.dev`) || Host(`shop.flght.dev`)');
});

it('rejects an extra hostname the wildcard certificate does not cover', function (string $hostname) {
    expect(invalidPhp(['hostnames' => [$hostname]]))
        ->toContain('Invalid "app.hostnames.0"')
        ->toContain('Expected a lowercase subdomain such as "admin"');
})->with(['shop.myapp', 'Shop', '-shop', 'shop.flght.dev']);

it('refuses two services serving the same hostname', function () {
    flightProject(phpApp(settings: ['services' => ['admin' => ['type' => 'php', 'hostnames' => ['myapp']]]]));

    expect(fn () => writeProjectCompose())->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toContain('Invalid "services.admin.hostnames"')
            ->and($e->hint())->toBe('Expected myapp.flght.dev to be served once, but app already serves it.');
    });
});

it('lists every hostname, the app first', function () {
    flightProject(phpApp(['hostnames' => ['shop']], ['services' => ['admin' => ['type' => 'php']]]));

    $hostnames = array_map(
        fn ($service) => $service->hostnames(),
        app(ProjectStack::class)->services(),
    );

    expect($hostnames)->toBe([
        ['myapp.flght.dev', 'shop.flght.dev'],
        ['myapp-admin.flght.dev'],
    ]);
});

it('leaves options a recipe does not set to the service defaults', function () {
    flightProject(['recipe' => 'laravel']);

    $app = writeProjectCompose()['services']['app'];

    expect($app['build']['context'])->toBe('./.flight/app/build')
        ->and(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'))->toContain('serversideup/php:8.4-fpm-nginx')
        ->and($app['environment']['NGINX_WEBROOT'])->toBe('/var/www/html/public');
});

it('installs extensions and wp-cli in the image when asked', function () {
    flightProject(phpApp(['extensions' => ['mysqli', 'gd'], 'wp_cli' => true]));

    writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/app/build/Dockerfile');

    expect($dockerfile)->toContain("RUN install-php-extensions mysqli gd\n")
        ->and($dockerfile)->toContain('ADD --checksum=sha256:ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c --chmod=755 https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar /usr/local/bin/wp')
        // Installed as root, before switching back.
        ->and(strpos($dockerfile, 'install-php-extensions'))->toBeLessThan(strpos($dockerfile, 'USER www-data'));
});

it('adds neither by default', function () {
    flightProject();

    writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/app/build/Dockerfile');

    expect($dockerfile)->not->toContain('install-php-extensions')
        ->and($dockerfile)->not->toContain('wp-cli');
});

it('rejects an extension name that is not one', function () {
    expect(invalidPhp(['extensions' => ['mysqli; rm -rf /']]))
        ->toContain('Invalid "app.extensions.0"')
        ->toContain('Expected an extension name');
});

it('mounts the project as the app by default', function () {
    flightProject();

    expect(writeProjectCompose()['services']['app']['volumes'])->toBe(['.:/var/www/html'])
        ->and(getcwd().'/.flight/app/data')->not->toBeDirectory();
});

it('mounts the project inside an app kept in .flight when project_path is set', function () {
    flightProject(phpApp(['project_path' => 'modules/my-module', 'webroot' => '.']));

    $app = writeProjectCompose()['services']['app'];

    expect($app['volumes'])->toBe([
        './.flight/app/data:/var/www/html',
        '.:/var/www/html/modules/my-module',
    ])
        ->and($app['environment']['NGINX_WEBROOT'])->toBe('/var/www/html')
        ->and($app['working_dir'])->toBe('/var/www/html')
        // Created as the host user, so Docker doesn't create it as root.
        ->and(getcwd().'/.flight/app/data/modules/my-module')->toBeDirectory()
        // Kept out of the image's build context.
        ->and(getcwd().'/.flight/app/build/Dockerfile')->toBeFile();
});

it('rejects a project_path outside the app', function (string $path) {
    expect(invalidPhp(['project_path' => $path]))
        ->toContain('Invalid "app.project_path"')
        ->toContain('Expected a path inside the app');
})->with(['/var/www', '../elsewhere']);

it('installs debian packages in the image', function () {
    flightProject(phpApp(['packages' => ['git', 'mariadb-client']]));

    writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/app/build/Dockerfile');

    expect($dockerfile)->toContain("RUN docker-php-serversideup-dep-install-debian \"git mariadb-client\"\n")
        ->and(strpos($dockerfile, 'dep-install-debian'))->toBeLessThan(strpos($dockerfile, 'USER www-data'));
});

it('installs less with wp-cli, which pages its help through it', function () {
    flightProject(phpApp(['wp_cli' => true, 'packages' => ['less', 'git']]));

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'))
        ->toContain('RUN docker-php-serversideup-dep-install-debian "less git"');
});

it('rejects a package name that is not one', function () {
    expect(invalidPhp(['packages' => ['git && curl evil']]))
        ->toContain('Invalid "app.packages.0"')
        ->toContain('Expected a Debian package name');
});

it('runs workers on the app image, each in a container of its own', function () {
    flightProject(phpApp(['version' => '8.3', 'workers' => [
        'queue' => 'php artisan queue:work',
        'scheduler' => ['run' => 'php artisan schedule:work'],
    ]]));

    $services = writeProjectCompose()['services'];

    expect(array_keys($services))->toBe(['app', 'queue', 'scheduler'])
        // The app names its image, so the workers can run it too.
        ->and($services['app']['image'])->toBe('flight-myapp-app')
        ->and($services['app'])->toHaveKey('build');

    $queue = $services['queue'];

    expect($queue['image'])->toBe('flight-myapp-app')
        ->and($queue['pull_policy'])->toBe('never')
        ->and($queue)->not->toHaveKeys(['build', 'labels', 'ports'])
        ->and($queue['depends_on'])->toBe(['app'])
        ->and($queue['command'])->toBe(['sh', '-c', 'php artisan queue:work'])
        ->and($queue['healthcheck'])->toBe(['test' => ['CMD', 'true'], 'start_period' => '10s', 'start_interval' => '1s'])
        // The same mounts as the app.
        ->and($queue['volumes'])->toBe($services['app']['volumes'])
        // The same environment, minus the web server's certificate.
        ->and($queue['environment'])->toBe([...$services['app']['environment'], 'SSL_MODE' => 'off'])
        // Nothing to serve, so it stays off the proxy's network.
        ->and($queue['networks'])->toBe(['default'])
        ->and($services['scheduler']['command'])->toBe(['sh', '-c', 'php artisan schedule:work']);
});

it('leaves the image unnamed without workers', function () {
    flightProject();

    expect(writeProjectCompose()['services']['app'])->not->toHaveKey('image');
});

it('rejects invalid workers', function (mixed $workers, string $key) {
    expect(invalidPhp(['workers' => $workers]))->toContain("Invalid \"{$key}\"");
})->with([
    'a list' => [['php artisan queue:work'], 'app.workers'],
    'a name with spaces' => [['my queue' => 'php artisan queue:work'], 'app.workers.my queue'],
    'no command' => [['queue' => ['run' => 42]], 'app.workers.queue'],
]);

it('rejects a worker with the name of a service', function () {
    flightProject(phpApp(['workers' => ['mariadb' => 'true']], ['services' => ['mariadb' => ['type' => 'mariadb']]]));

    expect(fn () => writeProjectCompose())->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toContain('Invalid "app.workers.mariadb"')
            ->and($e->hint())->toBe('Expected "mariadb" to be used once, but services.mariadb already uses it.');
    });
});

it('rejects two workers with the same name', function () {
    flightProject(phpApp(['workers' => ['queue' => 'true']], ['services' => [
        'legacy' => ['type' => 'php', 'workers' => ['queue' => 'true']],
    ]]));

    expect(fn () => writeProjectCompose())->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toContain('Invalid "services.legacy.workers.queue"')
            ->and($e->hint())->toContain('app.workers.queue already uses it');
    });
});

it('leaves out the access log of each server, keeping errors', function (string $server, array $environment, bool $dockerfile) {
    flightProject(phpApp(['server' => $server]));

    $app = writeProjectCompose()['services']['app'];

    expect($app['environment'])->toMatchArray($environment)
        ->and(str_contains(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'), 'CustomLog'))->toBe($dockerfile);
})->with([
    'nginx' => ['fpm-nginx', ['NGINX_ACCESS_LOG' => '/dev/null'], false],
    'frankenphp' => ['frankenphp', ['LOG_OUTPUT_LEVEL' => 'warn'], false],
    // Apache has no setting for it, so the image's config is changed.
    'apache' => ['fpm-apache', [], true],
]);

it('keeps the access log when asked', function () {
    flightProject(phpApp(['server' => 'fpm-apache', 'access_log' => true]));

    $app = writeProjectCompose()['services']['app'];

    expect($app['environment'])->not->toHaveKeys(['NGINX_ACCESS_LOG', 'LOG_OUTPUT_LEVEL'])
        ->and(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'))->not->toContain('CustomLog');
});

it('installs node next to php when asked', function () {
    flightProject("app:\n  type: php\n  node: 22\n");

    writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/app/build/Dockerfile');

    expect($dockerfile)->toContain('COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node')
        ->and($dockerfile)->toContain('COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules')
        ->and($dockerfile)->toContain('ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm')
        ->and(strpos($dockerfile, 'node:22-slim'))->toBeLessThan(strpos($dockerfile, 'USER www-data'));
});

it('rejects a node version that is not one', function () {
    expect(invalidPhp(['node' => 'latest; rm -rf /']))
        ->toContain('Invalid "app.node"')
        ->toContain('Expected a Node.js version such as "22"');
});

it('asks to quote a decimal node version', function () {
    expect(invalidPhp(['node' => 20.10]))
        ->toContain('Invalid "app.node"')
        ->toContain('Expected a Node.js version in quotes, such as "20.10"');
});

it('says in the dockerfile where to change it', function () {
    flightProject(phpApp(settings: ['services' => ['legacy' => ['type' => 'php']]]));

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/app/build/Dockerfile'))->toContain('# change app in flight.yaml instead.')
        ->and(file_get_contents(getcwd().'/.flight/legacy/build/Dockerfile'))->toContain('# change services.legacy in flight.yaml instead.');
});
