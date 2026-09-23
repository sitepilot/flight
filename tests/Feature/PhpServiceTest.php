<?php

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Scaffold;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    flightDirectory();
});

/**
 * Generate the project's compose file and return it parsed.
 */
function writeProjectCompose(): array
{
    app(Scaffold::class)->write(app(ProjectStack::class));

    return Yaml::parseFile(getcwd().'/.flight/compose.yaml');
}

function invalidPhp(array $options): string
{
    flightProject(['services' => ['php' => $options]]);

    try {
        writeProjectCompose();
    } catch (FlightException $e) {
        return $e->getMessage().' '.$e->hint();
    }

    throw new RuntimeException('Expected the options to be rejected.');
}

it('generates a routed php service built from serversideup/php', function () {
    flightProject();

    $compose = writeProjectCompose();
    $php = $compose['services']['php'];

    expect($compose['name'])->toBe('flight-myapp')
        ->and($php['build']['context'])->toBe('./.flight/php')
        ->and($php['build']['args'])->toHaveKeys(['USER_ID', 'GROUP_ID'])
        ->and($php['pull_policy'])->toBe('build')
        ->and($php['volumes'])->toBe(['.:/var/www/html'])
        ->and($php['networks'])->toBe(['default', 'flight'])
        ->and($php['environment'])->toBe(['NGINX_WEBROOT' => '/var/www/html/public', 'SSL_MODE' => 'off'])
        ->and($php['labels'])->toBe([
            'traefik.enable' => 'true',
            'traefik.http.routers.flight-myapp-php.rule' => 'Host(`myapp.flght.dev`)',
            'traefik.http.services.flight-myapp-php.loadbalancer.server.port' => '8080',
        ])
        ->and($compose['networks']['flight'])->toBe(['name' => 'flight', 'external' => true]);
});

it('builds with the host user id so the project stays writable', function () {
    flightProject();

    $args = writeProjectCompose()['services']['php']['build']['args'];

    $uid = posix_getuid() === 0 ? 33 : posix_getuid();

    expect($args['USER_ID'])->toBe((string) $uid);
});

it('writes a dockerfile for the chosen version and server', function () {
    flightProject(['services' => ['php' => ['version' => '8.3', 'server' => 'frankenphp']]]);

    $compose = writeProjectCompose();
    $dockerfile = file_get_contents(getcwd().'/.flight/php/Dockerfile');

    expect($dockerfile)->toContain('FROM serversideup/php:8.3-frankenphp')
        ->and($dockerfile)->toContain('docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID')
        ->and($dockerfile)->toContain('USER www-data')
        ->and($compose['services']['php']['environment'])->toHaveKey('CADDY_SERVER_ROOT');
});

it('accepts an unquoted version', function () {
    flightProject("services:\n  php:\n    version: 8.2\n");

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/php/Dockerfile'))->toContain('FROM serversideup/php:8.2-fpm-nginx');
});

it('serves the project root when the webroot is empty', function () {
    flightProject(['services' => ['php' => ['webroot' => '.', 'server' => 'fpm-apache']]]);

    expect(writeProjectCompose()['services']['php']['environment']['APACHE_DOCUMENT_ROOT'])->toBe('/var/www/html');
});

it('follows the configured domain and network', function () {
    flightConfig(['domain' => 'test.dev', 'network' => 'proxy']);
    flightProject(['name' => 'shop', 'services' => ['php' => null]]);

    $compose = writeProjectCompose();

    expect($compose['services']['php']['labels']['traefik.http.routers.flight-shop-php.rule'])->toBe('Host(`shop.test.dev`)')
        ->and($compose['networks']['flight']['name'])->toBe('proxy');
});

it('names the offending key when an option is invalid', function (array $options, string $key) {
    expect(invalidPhp($options))->toContain("Invalid \"services.php.{$key}\"");
})->with([
    'unsupported version' => [['version' => '7.0'], 'version'],
    'unknown server' => [['server' => 'nginx'], 'server'],
    'webroot outside the project' => [['webroot' => '../elsewhere'], 'webroot'],
    'absolute webroot' => [['webroot' => '/srv'], 'webroot'],
]);

it('lists the supported versions', function () {
    expect(invalidPhp(['version' => '7.0']))
        ->toContain('Expected services.php.version to be one of: 8.1, 8.2, 8.3, 8.4, 8.5.');
});

it('rejects an unknown option', function () {
    expect(invalidPhp(['verison' => '8.3']))
        ->toContain('Invalid "services.php.verison"')
        ->toContain('Expected one of: version, server, webroot, hostnames.');
});

it('rejects an unknown service type', function () {
    flightProject(['services' => ['redis' => null]]);

    expect(fn () => writeProjectCompose())->toThrow(FlightException::class, 'Invalid "services.redis.type"');
});

it('keeps generated files out of git but not the override', function () {
    flightProject();

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/.gitignore'))->toContain("*\n!compose.override.yaml");
});

it('serves the first routed service at the project hostname and the rest beside it', function () {
    flightProject(['services' => ['php' => null, 'admin' => ['type' => 'php']]]);

    $services = writeProjectCompose()['services'];

    expect($services['php']['labels']['traefik.http.routers.flight-myapp-php.rule'])->toBe('Host(`myapp.flght.dev`)')
        ->and($services['admin']['labels']['traefik.http.routers.flight-myapp-admin.rule'])->toBe('Host(`myapp-admin.flght.dev`)');
});

it('follows the order of flight.yaml when picking the first service', function () {
    flightProject(['services' => ['admin' => ['type' => 'php'], 'php' => null]]);

    $services = writeProjectCompose()['services'];

    expect($services['admin']['labels']['traefik.http.routers.flight-myapp-admin.rule'])->toBe('Host(`myapp.flght.dev`)')
        ->and($services['php']['labels']['traefik.http.routers.flight-myapp-php.rule'])->toBe('Host(`myapp-php.flght.dev`)');
});

it('adds extra hostnames alongside the assigned one', function () {
    flightProject(['services' => ['php' => ['hostnames' => ['shop', 'api']]]]);

    expect(writeProjectCompose()['services']['php']['labels']['traefik.http.routers.flight-myapp-php.rule'])
        ->toBe('Host(`myapp.flght.dev`) || Host(`shop.flght.dev`) || Host(`api.flght.dev`)');
});

it('accepts a single extra hostname without a list', function () {
    flightProject("services:\n  php:\n    hostnames: shop\n");

    expect(writeProjectCompose()['services']['php']['labels']['traefik.http.routers.flight-myapp-php.rule'])
        ->toBe('Host(`myapp.flght.dev`) || Host(`shop.flght.dev`)');
});

it('rejects an extra hostname the wildcard certificate does not cover', function (string $hostname) {
    expect(invalidPhp(['hostnames' => [$hostname]]))
        ->toContain('Invalid "services.php.hostnames.0"')
        ->toContain('Expected a lowercase subdomain such as "admin"');
})->with(['shop.myapp', 'Shop', '-shop', 'shop.flght.dev']);

it('refuses two services serving the same hostname', function () {
    flightProject(['services' => ['php' => null, 'admin' => ['type' => 'php', 'hostnames' => ['myapp']]]]);

    expect(fn () => writeProjectCompose())->toThrow(
        FlightException::class,
        'Invalid "services.admin.hostnames"',
    );
});

it('lists every hostname in the summary order', function () {
    flightProject(['services' => ['php' => ['hostnames' => ['shop']], 'admin' => ['type' => 'php']]]);

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

    $php = writeProjectCompose()['services']['php'];

    expect($php['build']['context'])->toBe('./.flight/php')
        ->and(file_get_contents(getcwd().'/.flight/php/Dockerfile'))->toContain('serversideup/php:8.4-fpm-nginx')
        ->and($php['environment']['NGINX_WEBROOT'])->toBe('/var/www/html/public');
});
