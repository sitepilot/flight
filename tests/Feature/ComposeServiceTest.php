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
 *
 * @param  array<string, mixed>  $settings
 * @param  array<string, string>  $files
 */
function composeProject(array $settings = [], array $files = []): string
{
    $project = flightProject([
        'compose' => ['compose.yml', ['path' => 'compose.override.yml', 'required' => false]],
        'app' => ['type' => 'compose', 'origin' => 'https://app:8443'],
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

it('serves the service its origin names', function () {
    composeProject([
        'app' => ['type' => 'compose', 'origin' => 'https://web:8443'],
        'services' => ['mailpit' => ['type' => 'compose', 'origin' => 'http://mailpit:8025']],
    ]);

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
    composeProject(['app' => ['type' => 'compose', 'origin' => 'https://web:8443']]);
    $commands = fakeCommands();

    $this->artisan('logs')->assertExitCode(0);
    $this->artisan('logs', ['service' => 'mssql'])->assertExitCode(0);

    expect($commands[0])->toEndWith('logs web')
        ->and($commands[1])->toEndWith('logs mssql');
});

it('runs provisioning steps in the app, or a service from the compose files', function () {
    composeProject([
        'app' => ['type' => 'compose', 'origin' => 'https://web:8443'],
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
    'no origin' => [['app' => ['type' => 'compose']], 'Expected where the proxy reaches the service'],
    'an origin without a port' => [['app' => ['type' => 'compose', 'origin' => 'https://app']], 'Expected where the proxy reaches the service'],
    'no compose files' => [['compose' => null], 'Expected the compose files to run, set at the top level'],
    'compose files that are not a list' => [['compose' => 'compose.yml'], 'Expected the compose files to run, such as [compose.yml].'],
    'a file outside the project' => [['compose' => ['../compose.yml']], 'Expected a path inside the folder of flight.yaml'],
    'only missing optional files' => [['compose' => [['path' => 'compose.dev.yml', 'required' => false]]], 'Expected the compose files to run, set at the top level'],
    'a missing file' => [['compose' => ['compose.yml', 'compose.dev.yml']], 'Expected compose.dev.yml to exist, or to be marked `required: false`.'],
    'workers' => [['app' => ['type' => 'compose', 'origin' => 'https://app:8443', 'workers' => ['queue' => 'php artisan queue:work']]], 'Expected one of: origin, hostnames.'],
]);

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
