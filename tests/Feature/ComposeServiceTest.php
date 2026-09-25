<?php

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    flightDirectory();

    $this->compose = <<<'YAML'
        services:
          app:
            image: example/app
            environment:
              DB_PASSWORD: ${DB_PASSWORD?}
            x-flight:
              origin: https://app:8443
          mssql:
            image: mcr.microsoft.com/mssql/server
            volumes:
              - mssql_data:/var/opt/mssql
        volumes:
          mssql_data:
        YAML;
});

/**
 * A project that runs compose.yml, and an optional compose.override.yml,
 * with its app from them.
 */
function composeProject(array $settings = [], array $files = []): string
{
    $project = flightProject([
        'compose' => ['compose.yml', ['path' => 'compose.override.yml', 'required' => false]],
        ...$settings,
    ]);

    foreach (['compose.yml' => test()->compose, ...$files] as $file => $contents) {
        @mkdir(dirname("{$project}/{$file}"), 0755, true);
        file_put_contents("{$project}/{$file}", $contents);
    }

    return $project;
}

function invalidCompose(array $settings, array $files = []): string
{
    composeProject($settings, $files);

    try {
        writeProjectCompose();
    } catch (FlightException $e) {
        return $e->getMessage().' '.$e->hint();
    }

    throw new RuntimeException('Expected the settings to be rejected.');
}

/**
 * The commands a Flight command runs, faked.
 */
function fakeCommands(): ArrayObject
{
    $commands = new ArrayObject;

    Process::fake(function ($process) use ($commands) {
        $commands[] = implode(' ', (array) $process->command);

        return Process::result('');
    });

    fakeValidCertificate();

    return $commands;
}

it('only adds the proxy to the app', function () {
    composeProject();

    $compose = writeProjectCompose();

    expect($compose['name'])->toBe('flight-myapp')
        ->and($compose['services'])->toBe(['app' => [
            'labels' => [
                'traefik.enable' => 'true',
                'traefik.http.routers.flight-myapp-app.rule' => 'Host(`myapp.flght.dev`)',
                'traefik.http.services.flight-myapp-app.loadbalancer.server.port' => '8443',
                'traefik.http.services.flight-myapp-app.loadbalancer.server.scheme' => 'https',
            ],
            'networks' => ['default', 'flight'],
        ]])
        ->and($compose['networks']['flight'])->toBe(['name' => 'flight', 'external' => true]);
});

it('runs its own file first, then the compose files, under its flight name', function () {
    $project = composeProject();
    $commands = fakeCommands();

    $this->artisan('down')->assertExitCode(0);

    expect($commands[0])->toBe(
        "docker compose --project-directory {$project} -p flight-myapp -f {$project}/.flight/compose.yaml -f {$project}/compose.yml down --remove-orphans"
    );
});

it('runs an optional file when it exists', function () {
    $project = composeProject(files: ['compose.override.yml' => "services:\n  app:\n    ports: ['8080:8080']\n"]);
    $commands = fakeCommands();

    $this->artisan('down')->assertExitCode(0);

    expect($commands[0])->toContain("-f {$project}/.flight/compose.yaml -f {$project}/compose.yml -f {$project}/compose.override.yml down");
});

it('lets compose read the .env, as the compose files expect', function () {
    composeProject();

    expect(app(ProjectStack::class)->environment())->not->toHaveKey('COMPOSE_DISABLE_ENV_FILE');
});

it('runs from the folder of the first file, as compose does', function () {
    $project = composeProject(['compose' => ['.docker/compose.yml']], ['.docker/compose.yml' => $this->compose]);
    $commands = fakeCommands();

    $this->artisan('down')->assertExitCode(0);

    expect($commands[0])->toStartWith("docker compose --project-directory {$project}/.docker -p flight-myapp -f {$project}/.flight/compose.yaml -f {$project}/.docker/compose.yml");
});

it('writes paths from the folder of the first file', function () {
    composeProject([
        'compose' => ['.docker/compose.yml'],
        'services' => ['admin' => ['type' => 'php']],
    ], ['.docker/compose.yml' => $this->compose]);

    $admin = writeProjectCompose()['services']['admin'];

    expect($admin['build']['context'])->toBe('./../.flight/admin/build')
        ->and($admin['volumes'])->toBe(['./..:/var/www/html']);
});

/**
 * Compose files with the app as "web", and Mailpit.
 */
function webCompose(): string
{
    return <<<'YAML'
        services:
          web:
            image: example/app
            x-flight:
              app: true
              origin: https://web:8443
          mssql:
            image: mcr.microsoft.com/mssql/server
          mailpit:
            image: axllent/mailpit
            x-flight:
              origin: http://mailpit:8025
        YAML;
}

it('serves the services with x-flight, and the app marked as the app', function () {
    $this->compose = webCompose();
    composeProject();

    $compose = writeProjectCompose();

    expect(array_keys($compose['services']))->toBe(['web', 'mailpit'])
        ->and($compose['services']['web']['labels'])->toHaveKey('traefik.http.routers.flight-myapp-app.rule', 'Host(`myapp.flght.dev`)')
        ->and($compose['services']['mailpit']['labels'])->toMatchArray([
            'traefik.http.routers.flight-myapp-mailpit.rule' => 'Host(`myapp-mailpit.flght.dev`)',
            'traefik.http.services.flight-myapp-mailpit.loadbalancer.server.port' => '8025',
        ])
        ->and($compose['services']['mailpit']['labels'])->not->toHaveKey('traefik.http.services.flight-myapp-mailpit.loadbalancer.server.scheme');
});

it('uses the app by default and passes other services to compose', function () {
    $this->compose = webCompose();
    composeProject();
    $commands = fakeCommands();

    $this->artisan('logs')->assertExitCode(0);
    $this->artisan('logs', ['service' => 'mssql'])->assertExitCode(0);

    expect($commands[0])->toEndWith('logs web')
        ->and($commands[1])->toEndWith('logs mssql');
});

it('runs provisioning steps in the app, or a service from the compose files', function () {
    $this->compose = webCompose();
    composeProject([
        'provision' => [
            ['name' => 'Migrate', 'run' => 'php artisan migrate'],
            ['name' => 'Seed', 'service' => 'mssql', 'run' => 'seed'],
        ],
    ]);
    $commands = fakeCommands();

    $this->artisan('up')->assertExitCode(0);

    $execs = collect($commands)->filter(fn (string $command): bool => str_contains($command, ' exec '))->values();

    expect($execs[0])->toContain(' exec -T web sh -c php artisan migrate')
        ->and($execs[1])->toContain(' exec -T mssql sh -c seed');
});

it('rejects invalid settings', function (array $settings, string $error) {
    expect(invalidCompose($settings))->toContain($error);
})->with([
    'compose files that are not a list' => [['compose' => 'compose.yml'], 'Expected the compose files to run, such as [compose.yml].'],
    'a file outside the project' => [['compose' => ['../compose.yml']], 'Expected a path inside the folder of flight.yaml'],
    'a missing file' => [['compose' => ['compose.yml', 'compose.dev.yml']], 'Expected compose.dev.yml to exist, or to be marked `required: false`.'],
    'only missing optional files' => [['compose' => [['path' => 'compose.dev.yml', 'required' => false]]], 'Expected a service, such as `app: php:8.4`, or `x-flight`'],
    'the compose type in flight.yaml' => [['services' => ['mailpit' => ['type' => 'compose', 'origin' => 'http://mailpit:8025']]], 'Expected a type such as `mariadb:11.8`'],
    'the app in flight.yaml too' => [['app' => 'php'], 'Expected "app" in one place: `app:` in flight.yaml, or x-flight here.'],
]);

it('rejects an invalid x-flight, naming the compose file', function (string $xFlight, string $error) {
    $this->compose = "services:\n  app:\n    image: example/app\n    x-flight: {$xFlight}\n";

    expect(invalidCompose([]))->toContain('Invalid "services.app.x-flight')
        ->toContain('compose.yml.')
        ->toContain($error);
})->with([
    'no origin' => ['{}', 'Expected where the proxy reaches the service'],
    'an origin without a port' => ['{origin: https://app}', 'Expected where the proxy reaches the service'],
    'an origin of another service' => ['{origin: https://web:8443}', 'Expected the origin to name this service, such as "https://app:8443".'],
    'workers' => ['{origin: https://app:8443, workers: {queue: work}}', 'Expected one of: origin, hostnames.'],
    'a string' => ['https://app:8443', 'Expected where the proxy reaches the service'],
]);

it('rejects two apps', function () {
    $this->compose = <<<'YAML'
        services:
          app:
            image: example/app
            x-flight: {origin: https://app:8443}
          web:
            image: example/app
            x-flight: {app: true, origin: https://web:8443}
        YAML;

    expect(invalidCompose([]))->toContain('Expected one app, but services.app.x-flight is the app too.');
});

it('rejects a service name flight cannot use', function () {
    $this->compose = "services:\n  web.test:\n    image: example/app\n    x-flight: {origin: http://web.test:80}\n";

    expect(invalidCompose([]))->toContain('or `app: true` to make it the app.');
});

it('serves any service marked as the app', function () {
    $this->compose = "services:\n  web.test:\n    image: example/app\n    x-flight: {app: true, origin: http://web.test:80}\n";
    composeProject();

    expect(writeProjectCompose()['services']['web.test']['labels'])
        ->toHaveKey('traefik.http.routers.flight-myapp-app.rule', 'Host(`myapp.flght.dev`)');
});

it('lets a later compose file change x-flight', function () {
    composeProject(files: ['compose.override.yml' => "services:\n  app:\n    x-flight:\n      origin: http://app:8080\n"]);

    expect(writeProjectCompose()['services']['app']['labels'])
        ->toHaveKey('traefik.http.services.flight-myapp-app.loadbalancer.server.port', '8080');
});

it('runs x-flight services next to the services in flight.yaml', function () {
    composeProject(['services' => ['cache' => 'valkey']]);

    $compose = writeProjectCompose();

    expect(array_keys($compose['services']))->toContain('app', 'cache');
});

it('warns when the compose files also run under another name', function () {
    $project = composeProject();
    fakeValidCertificate();

    Process::fake(function ($process) use ($project) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'compose ls')
            ? Process::result(json_encode([
                ['Name' => 'flight', 'ConfigFiles' => '/home/me/.config/flight/compose.yaml'],
                ['Name' => 'myapp', 'ConfigFiles' => "{$project}/compose.yml,{$project}/compose.override.yml"],
            ]))
            : Process::result('');
    });

    $this->withoutMockingConsoleOutput()->artisan('up');

    expect(Artisan::output())->toContain('myapp runs from the same compose files.')
        ->not->toContain('flight runs from');
});

it('lists the files in the order it runs them', function () {
    composeProject(files: ['compose.override.yml' => "services: {}\n"]);

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/compose.yaml'))->toContain(<<<'TEXT'
        # Flight runs these files in this order; later files can change earlier ones:
        #   1. .flight/compose.yaml (this file)
        #   2. compose.yml
        #   3. compose.override.yml
        TEXT);
});

it('leaves out the order when it runs only its own file', function () {
    flightProject();

    writeProjectCompose();

    expect(file_get_contents(getcwd().'/.flight/compose.yaml'))->not->toContain('in this order');
});
