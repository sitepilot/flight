<?php

use App\Exceptions\FlightException;

beforeEach(function () {
    flightDirectory();
});

it('falls back to the defaults when no config file exists', function () {
    expect(flightSettings()->domain())->toBe('flght.dev')
        ->and(flightSettings()->network())->toBe('flight');
});

it('reads settings from config.yaml', function () {
    flightConfig([
        'domain' => 'test.dev',
        'network' => 'proxy',
    ]);

    expect(flightSettings()->domain())->toBe('test.dev')
        ->and(flightSettings()->network())->toBe('proxy');
});

it('reads config.yml when there is no config.yaml', function () {
    file_put_contents($this->flightDirectory.'/config.yml', "domain: yml.dev\n");

    expect(flightSettings()->file())->toBe($this->flightDirectory.'/config.yml')
        ->and(flightSettings()->domain())->toBe('yml.dev');
});

it('prefers config.yaml over config.yml', function () {
    file_put_contents($this->flightDirectory.'/config.yml', "domain: yml.dev\n");
    flightConfig(['domain' => 'yaml.dev']);

    expect(flightSettings()->domain())->toBe('yaml.dev');
});

it('creates config.yaml when there is neither', function () {
    flightSettings()->scaffold();

    expect($this->flightDirectory.'/config.yaml')->toBeFile()
        ->and($this->flightDirectory.'/config.yml')->not->toBeFile();
});

it('takes the global services from the proxy recipe', function () {
    expect(flightSettings()->recipe()->name())->toBe('proxy')
        ->and(flightSettings()->services())->toBe(['traefik' => ['type' => 'traefik']]);
});

it('merges services from config.yaml over the recipe', function () {
    flightConfig(['services' => ['traefik' => ['http_port' => 8080]]]);

    expect(flightSettings()->services())->toBe(['traefik' => ['type' => 'traefik', 'http_port' => 8080]]);
});

it('creates the directory and seeds a documented config file', function () {
    flightSettings()->scaffold();

    expect($this->flightDirectory.'/config.yaml')->toBeFile();

    $contents = file_get_contents($this->flightDirectory.'/config.yaml');

    expect($contents)->toContain('domain: flght.dev')
        ->and($contents)->toContain('network: flight')
        // The comments are why the stub is written by hand.
        ->and($contents)->toContain('# Flight configuration.')
        ->and($contents)->toContain('#   traefik:');
});

it('seeds a config file that loads', function () {
    flightSettings()->scaffold();

    expect(flightSettings()->load())->toHaveKey('services');
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
    'empty domain' => [['domain' => ''], 'domain'],
    'network name docker would reject' => [['network' => 'not a network'], 'network'],
    'services written as a list' => [['services' => ['traefik']], 'services'],
    'unknown key' => [['domian' => 'test.dev'], 'domian'],
]);

it('reports unparsable yaml against the file it came from', function () {
    file_put_contents($this->flightDirectory.'/config.yaml', "domain: [unclosed\n");

    flightSettings()->load();
})->throws(FlightException::class, 'Could not parse');
