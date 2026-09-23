<?php

use App\Exceptions\FlightException;

beforeEach(function () {
    flightDirectory();
});

it('falls back to the defaults when no config file exists', function () {
    expect(flightSettings()->domain())->toBe('flght.dev')
        ->and(flightSettings()->network())->toBe('flight')
        ->and(flightSettings()->httpPort())->toBe(80)
        ->and(flightSettings()->httpsPort())->toBe(443)
        ->and(flightSettings()->dockerSocket())->toBe('/var/run/docker.sock');
});

it('reads settings from config.yaml', function () {
    flightConfig([
        'domain' => 'test.dev',
        'network' => 'proxy',
        'http_port' => 8080,
        'https_port' => 8443,
    ]);

    expect(flightSettings()->domain())->toBe('test.dev')
        ->and(flightSettings()->network())->toBe('proxy')
        ->and(flightSettings()->httpPort())->toBe(8080)
        ->and(flightSettings()->httpsPort())->toBe(8443)
        // Untouched keys still come from the defaults.
        ->and(flightSettings()->dockerSocket())->toBe('/var/run/docker.sock');
});

it('creates the directory layout and seeds a documented config file', function () {
    flightSettings()->scaffold();

    expect($this->flightDirectory.'/certs')->toBeDirectory()
        ->and($this->flightDirectory.'/traefik')->toBeDirectory()
        ->and($this->flightDirectory.'/config.yaml')->toBeFile();

    $contents = file_get_contents($this->flightDirectory.'/config.yaml');

    expect($contents)->toContain('domain: flght.dev')
        ->and($contents)->toContain('network: flight')
        // The comments are why the stub is written by hand.
        ->and($contents)->toContain('# Flight configuration.');
});

it('never overwrites an existing config file', function () {
    flightConfig(['domain' => 'mine.dev']);

    flightSettings()->scaffold();

    expect(file_get_contents($this->flightDirectory.'/config.yaml'))->toContain('mine.dev');
});

it('rejects a setting the validator will not accept', function (array $settings, string $key) {
    flightConfig($settings);

    expect(fn () => flightSettings()->load())
        ->toThrow(FlightException::class, "Invalid \"{$key}\"");
})->with([
    'port out of range' => [['http_port' => 99999], 'http_port'],
    'port that is not a number' => [['http_port' => 'abc'], 'http_port'],
    'empty domain' => [['domain' => ''], 'domain'],
    'network name docker would reject' => [['network' => 'not a network'], 'network'],
    'empty docker socket' => [['docker_socket' => ''], 'docker_socket'],
]);

it('rejects identical http and https ports', function () {
    flightConfig(['http_port' => 443, 'https_port' => 443]);

    expect(fn () => flightSettings()->load())->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toContain('Invalid "https_port"')
            // The hint explains what is wrong.
            ->and($e->hint())->toContain('must differ');
    });
});

it('names the literal config key in the message, not a humanized one', function () {
    flightConfig(['http_port' => 99999]);

    expect(fn () => flightSettings()->load())->toThrow(function (FlightException $e) {
        // Laravel would say "http port".
        expect($e->hint())->toContain('http_port')
            ->and($e->hint())->not->toContain('http port');
    });
});

it('reports unparsable yaml against the file it came from', function () {
    file_put_contents($this->flightDirectory.'/config.yaml', "domain: [unclosed\n");

    flightSettings()->load();
})->throws(FlightException::class, 'Could not parse');
