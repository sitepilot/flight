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

it('finds flight.yaml from a subdirectory of the project', function () {
    $root = flightProject();

    mkdir($root.'/src/Http', 0755, true);
    chdir($root.'/src/Http');

    expect(project()->root())->toBe($root)
        ->and(project()->composeFile())->toBe($root.'/.flight/compose.yaml');
});

it('finds flight.yml as well', function () {
    $root = flightProject();
    rename($root.'/flight.yaml', $root.'/flight.yml');

    expect(project()->file())->toBe($root.'/flight.yml')
        ->and(project()->services())->toHaveKey('php');
});

it('prefers flight.yaml when both exist', function () {
    $root = flightProject();
    file_put_contents($root.'/flight.yml', "services:\n  legacy:\n    type: php\n");

    expect(project()->file())->toBe($root.'/flight.yaml');
});

it('names the file in use in its errors', function () {
    $root = flightProject();
    rename($root.'/flight.yaml', $root.'/flight.yml');
    file_put_contents($root.'/flight.yml', "name: My App\nservices:\n  php:\n");

    expect(fn () => project()->load())->toThrow(FlightException::class, $root.'/flight.yml');
});

it('explains a missing flight.yaml', function () {
    flightProject();
    unlink(getcwd().'/flight.yaml');

    expect(fn () => project()->root())->toThrow(FlightException::class, 'No flight.yaml found');
});

it('names the project after its directory by default', function () {
    flightProject(name: 'My Shop');

    expect(project()->name())->toBe('my-shop');
});

it('takes the name from flight.yaml when set', function () {
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

it('takes the services from a recipe', function () {
    flightProject(['recipe' => 'laravel']);

    expect(project()->recipe()->name())->toBe('laravel')
        ->and(project()->services())->toBe([
            'php' => ['type' => 'php', 'webroot' => 'public'],
        ]);
});

it('overrides a recipe option by option', function () {
    flightProject(['recipe' => 'laravel', 'services' => ['php' => ['version' => '8.3']]]);

    expect(project()->services()['php'])->toBe(['type' => 'php', 'webroot' => 'public', 'version' => '8.3']);
});

it('keeps the recipe options for a bare service', function () {
    flightProject("recipe: laravel\nservices:\n  php:\n");

    expect(project()->services()['php'])->toBe(['type' => 'php', 'webroot' => 'public']);
});

it('replaces lists instead of merging them', function () {
    $merged = (fn () => $this->merge(
        ['php' => ['hostnames' => ['shop', 'api']]],
        ['php' => ['hostnames' => ['admin']]],
    ))->call(project());

    expect($merged)->toBe(['php' => ['hostnames' => ['admin']]]);
});

it('adds services after the recipe services', function () {
    flightProject(['recipe' => 'laravel', 'services' => ['worker' => ['type' => 'php']]]);

    expect(array_keys(project()->services()))->toBe(['php', 'worker']);
});

it('rejects an unknown recipe', function () {
    flightProject(['recipe' => 'rails']);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "recipe"');
});

it('asks for services or a recipe', function () {
    flightProject(['name' => 'shop']);

    try {
        project()->load();
    } catch (FlightException $e) {
        expect($e->hint())->toContain('recipe');

        return;
    }

    throw new RuntimeException('Expected flight.yaml to be rejected.');
});

it('accepts a recipe written as a mapping without options', function () {
    flightProject("recipe:\n  laravel:\n");

    expect(project()->recipe()->name())->toBe('laravel');
});

it('rejects a recipe that is neither a name nor one mapping', function (mixed $recipe) {
    flightProject(['recipe' => $recipe]);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "recipe"');
})->with([
    'list' => [['laravel']],
    'two recipes' => [['laravel' => null, 'proxy' => null]],
]);

it('rejects recipe options that are not a mapping', function () {
    flightProject(['recipe' => ['laravel' => 'yes']]);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "recipe.laravel"');
});

it('rejects an option the recipe does not have', function () {
    flightProject(['recipe' => ['laravel' => ['admin' => 'nick']]]);

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "recipe.laravel.admin"');
});

it('rejects an invalid provision step', function (mixed $provision, string $key) {
    flightProject(['services' => ['php' => null], 'provision' => $provision]);

    expect(fn () => project()->load())->toThrow(FlightException::class, "Invalid \"{$key}\"");
})->with([
    'not a list' => [['name' => 'Install'], 'provision'],
    'missing command' => [[['name' => 'Install', 'service' => 'php']], 'provision.0.run'],
    'unknown key' => [[['name' => 'Install', 'service' => 'php', 'run' => 'true', 'user' => 'root']], 'provision.0'],
]);
