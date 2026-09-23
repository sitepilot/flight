<?php

use App\Services\Traefik;
use App\Stacks\GlobalStack;
use App\Support\Scaffold;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\StubService;

beforeEach(function () {
    flightDirectory();

    StubService::$enabled = true;
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
        ->and($traefik['volumes'])->toContain('./traefik:/opt/flight/config:ro')
        ->and($traefik['volumes'])->toContain('./certs:/opt/flight/certs:ro')
        ->and($traefik['volumes'])->toContain('/var/run/docker.sock:/var/run/docker.sock')
        ->and($traefik['command'])->toContain('--providers.docker.exposedByDefault=false')
        ->and($traefik['command'])->toContain('--providers.file.directory=/opt/flight/config');
});

it('enables traefik on itself so the dashboard router registers', function () {

    $labels = writeCompose()['services']['traefik']['labels'];

    // Needed because exposedByDefault is false.
    expect($labels['traefik.enable'])->toBe('true')
        ->and($labels['traefik.http.routers.traefik.rule'])->toBe('Host(`traefik.flght.dev`)')
        ->and($labels['traefik.http.services.traefik.loadbalancer.server.port'])->toBe('8080');
});

it('names the default network after the configured network', function () {
    flightConfig(['network' => 'proxy']);

    $compose = writeCompose();

    expect($compose['networks']['default']['name'])->toBe('proxy')
        ->and($compose['services']['traefik']['command'])
        ->toContain('--providers.docker.network=proxy');
});

it('follows the configured domain and ports', function () {
    flightConfig(['domain' => 'test.dev', 'http_port' => 8080, 'https_port' => 8443]);

    $traefik = writeCompose()['services']['traefik'];

    expect($traefik['ports'])->toBe(['8080:80', '8443:443'])
        ->and($traefik['labels']['traefik.http.routers.traefik.rule'])
        ->toBe('Host(`traefik.test.dev`)');
});

it('merges any registered service and calls its prepare hook', function () {
    config(['flight.services' => [Traefik::class, StubService::class]]);

    $compose = writeCompose();

    expect($compose['services'])->toHaveKeys(['traefik', 'stub'])
        ->and($compose['services']['stub']['image'])->toBe('stub:latest')
        ->and($compose['volumes'])->toHaveKey('stub_data')
        ->and(StubService::$prepared)->toBeTrue();
});

it('leaves a disabled service out of the compose file', function () {
    config(['flight.services' => [Traefik::class, StubService::class]]);
    StubService::$enabled = false;

    $compose = writeCompose();

    expect($compose['services'])->toHaveKey('traefik')
        ->and($compose['services'])->not->toHaveKey('stub')
        ->and(StubService::$prepared)->toBeFalse();
});

it('writes the traefik tls store as a side effect of preparing', function () {

    writeCompose();

    $tls = Yaml::parseFile($this->flightDirectory.'/traefik/tls.yml');

    expect($tls['tls']['stores']['default']['defaultCertificate'])->toBe([
        'certFile' => '/opt/flight/certs/ssl.crt',
        'keyFile' => '/opt/flight/certs/ssl.key',
    ]);
});

it('never touches a user owned override file', function () {

    flightSettings()->scaffold();
    file_put_contents($this->flightDirectory.'/compose.override.yaml', "services:\n  mine: {}\n");

    writeCompose();

    expect(file_get_contents($this->flightDirectory.'/compose.override.yaml'))->toContain('mine');
});
