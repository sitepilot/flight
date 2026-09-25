<?php

use App\Exceptions\FlightException;
use App\Stacks\GlobalStack;
use App\Support\Scaffold;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\StubService;

beforeEach(function () {
    flightDirectory();
    Process::fake(fn ($process) => composeConfig($process, 'flight') ?? Process::result(''));

    StubService::$prepared = false;
});

function writeCompose(): array
{
    flightSettings()->scaffold();
    app(Scaffold::class)->write(app(GlobalStack::class));

    return Yaml::parseFile(flightSettings()->composeFile());
}

it('generates a compose file matching the ported traefik service', function () {

    $compose = writeCompose();

    expect($compose['name'])->toBe('flight');

    $traefik = $compose['services']['traefik'];

    expect($traefik['image'])->toBe('traefik:v3.7')
        ->and($traefik['restart'])->toBe('unless-stopped')
        ->and($traefik['ports'])->toBe(['80:80', '443:443'])
        ->and($traefik['volumes'])->toContain($this->flightDirectory.'/traefik:/opt/flight/config:ro')
        ->and($traefik['volumes'])->toContain($this->flightDirectory.'/.flight/certs:/opt/flight/certs:ro')
        ->and($traefik['volumes'])->toContain('/var/run/docker.sock:/var/run/docker.sock')
        ->and($traefik['command'])->toContain('--providers.docker.exposedByDefault=false')
        ->and($traefik['command'])->toContain('--providers.file.directory=/opt/flight/config');
});

it('enables traefik on itself so the dashboard router registers', function () {

    $labels = writeCompose()['services']['traefik']['labels'];

    // Needed because exposedByDefault is false.
    expect($labels['traefik.enable'])->toBe('true')
        ->and($labels['traefik.http.routers.flight-traefik.rule'])->toBe('Host(`traefik.flght.dev`)')
        ->and($labels['traefik.http.services.flight-traefik.loadbalancer.server.port'])->toBe('8080');
});

it('names the default network after the configured network', function () {
    flightConfig(['network' => 'proxy']);

    $compose = writeCompose();

    expect($compose['networks'])->toBe(['default' => ['name' => 'proxy']])
        ->and($compose['services']['traefik']['networks'])->toBe(['default'])
        ->and($compose['services']['traefik']['command'])
        ->toContain('--providers.docker.network=proxy');
});

it('follows the configured domain and ports', function () {
    flightConfig(['domain' => 'test.dev', 'services' => ['traefik' => ['http_port' => 8080, 'https_port' => 8443]]]);

    $traefik = writeCompose()['services']['traefik'];

    expect($traefik['ports'])->toBe(['8080:80', '8443:443'])
        ->and($traefik['command'])->toContain('--entrypoints.web.http.redirections.entrypoint.to=:8443')
        ->and($traefik['labels']['traefik.http.routers.flight-traefik.rule'])
        ->toBe('Host(`traefik.test.dev`)');
});

it('merges any registered service listed in config.yaml and calls its prepare hook', function () {
    config(['flight.services.stub' => StubService::class]);
    flightConfig(['services' => ['stub' => ['type' => 'stub']]]);

    $compose = writeCompose();

    expect($compose['services'])->toHaveKeys(['traefik', 'stub'])
        ->and($compose['services']['stub']['image'])->toBe('stub:latest')
        ->and($compose['volumes'])->toHaveKey('stub_data')
        ->and(StubService::$prepared)->toBeTrue();
});

it('rejects a service type that is not registered', function () {
    flightConfig(['services' => ['mailpit' => ['type' => 'mailpit']]]);

    writeCompose();
})->throws(FlightException::class, 'Invalid "services.mailpit.type"');

it('rejects options a service does not have', function () {
    flightConfig(['services' => ['traefik' => ['port' => 80]]]);

    writeCompose();
})->throws(FlightException::class, 'Invalid "services.traefik.port"');

it('writes the traefik tls store as a side effect of preparing', function () {

    writeCompose();

    $tls = Yaml::parseFile($this->flightDirectory.'/traefik/tls.yaml');

    expect($tls['tls']['stores']['default']['defaultCertificate'])->toBe([
        'certFile' => '/opt/flight/certs/ssl.crt',
        'keyFile' => '/opt/flight/certs/ssl.key',
    ]);
});

it('never touches a compose file of the user', function () {
    flightConfig(['compose' => ['compose.override.yaml']]);
    file_put_contents($this->flightDirectory.'/compose.override.yaml', "services:\n  mine: {}\n");

    writeCompose();

    expect(file_get_contents($this->flightDirectory.'/compose.override.yaml'))->toBe("services:\n  mine: {}\n");
});
it('serves a service from compose files listed in config.yaml', function () {
    flightConfig(['compose' => ['mailpit.yaml']]);
    file_put_contents($this->flightDirectory.'/mailpit.yaml', "services:\n  mailpit:\n    image: axllent/mailpit\n    x-flight:\n      origin: http://mailpit:8025\n");

    $mailpit = writeCompose()['services']['mailpit'];

    expect($mailpit['labels'])->toMatchArray([
        'traefik.http.routers.flight-mailpit.rule' => 'Host(`mailpit.flght.dev`)',
        'traefik.http.services.flight-mailpit.loadbalancer.server.port' => '8025',
    ])->and($mailpit)->not->toHaveKey('image');
});

it('keeps its generated files in .flight, out of git', function () {
    writeCompose();

    expect($this->flightDirectory.'/.flight/compose.yaml')->toBeFile()
        ->and(file_get_contents($this->flightDirectory.'/.flight/.gitignore'))->toBe("# Generated by flight.\n*\n")
        ->and($this->flightDirectory.'/compose.yaml')->not->toBeFile();
});
