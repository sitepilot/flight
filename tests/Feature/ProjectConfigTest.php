<?php

use App\Exceptions\FlightException;
use App\Support\ProjectConfig;

beforeEach(function () {
    flightDirectory();
});

function project(): ProjectConfig
{
    return app(ProjectConfig::class);
}

it('finds flight.yml from a subdirectory of the project', function () {
    $root = flightProject();

    mkdir($root.'/src/Http', 0755, true);
    chdir($root.'/src/Http');

    expect(project()->root())->toBe($root)
        ->and(project()->composeFile())->toBe($root.'/.flight/compose.yaml');
});

it('explains a missing flight.yml', function () {
    flightProject();
    unlink(getcwd().'/flight.yml');

    expect(fn () => project()->root())->toThrow(FlightException::class, 'No flight.yml found');
});

it('names the project after its directory by default', function () {
    flightProject(name: 'My Shop');

    expect(project()->name())->toBe('my-shop');
});

it('takes the name from flight.yml when set', function () {
    flightProject(['name' => 'shop', 'services' => ['php' => null]]);

    expect(project()->name())->toBe('shop');
});

it('fills in each service type from its name', function () {
    flightProject(['services' => ['php' => null, 'legacy' => ['type' => 'php', 'version' => '8.1']]]);

    expect(project()->services())->toBe([
        'php' => ['type' => 'php'],
        'legacy' => ['type' => 'php', 'version' => '8.1'],
    ]);
});

it('rejects an invalid name', function () {
    flightProject(['name' => 'My App', 'services' => ['php' => null]]);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "name"');
});

it('requires services', function () {
    flightProject(['name' => 'shop']);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "services"');
});

it('rejects services written as a list', function () {
    flightProject("services:\n  - php\n");

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "services"');
});

it('rejects service options that are not a mapping', function () {
    flightProject("services:\n  php: 8.4\n");

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "services.php"');
});

it('reports a yaml syntax error', function () {
    flightProject("services: [\n");

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Could not parse');
});
