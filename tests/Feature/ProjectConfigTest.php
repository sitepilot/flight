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
        ->and(project()->services())->toHaveKey('app');
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
    flightProject(['name' => 'shop', 'app' => ['type' => 'php']]);

    expect(project()->name())->toBe('shop');
});

it('splits the version from the type', function () {
    flightProject(['services' => ['db' => ['type' => 'mariadb:11.4', 'database' => 'shop'], 'cache' => ['type' => 'valkey']]]);

    expect(project()->services())->toBe([
        'db' => ['type' => 'mariadb', 'version' => '11.4', 'database' => 'shop'],
        'cache' => ['type' => 'valkey'],
    ]);
});

it('reads a string as the type', function () {
    flightProject("app: php:8.3\nservices:\n  db: mariadb:11.4\n  cache: valkey\n");

    expect(project()->services())->toBe([
        'app' => ['type' => 'php', 'version' => '8.3'],
        'db' => ['type' => 'mariadb', 'version' => '11.4'],
        'cache' => ['type' => 'valkey'],
    ]);
});

it('keeps the recipe options when its app is written as a string', function () {
    flightProject("recipe: laravel\napp: php:8.3\n");

    expect(project()->services()['app'])->toBe(['type' => 'php', 'version' => '8.3', 'webroot' => 'public', 'extensions' => ['bcmath', 'exif', 'gd', 'intl']]);
});

it('rejects another type than the recipe sets', function (string $yaml, string $key) {
    flightProject($yaml);

    expect(fn () => project()->load())->toThrow(FlightException::class, "Invalid \"{$key}\"");
})->with([
    'the app' => ["recipe: laravel\napp: mariadb\n", 'app.type'],
    'a service' => ["recipe: wordpress\nservices:\n  mariadb:\n    type: valkey\n", 'services.mariadb.type'],
]);

it('names the recipe and its type when the type differs', function () {
    flightProject("recipe: wordpress\nservices:\n  mariadb: valkey:8.0\n");

    try {
        project()->load();
    } catch (FlightException $e) {
        expect($e->hint())->toBe('Expected mariadb, as set by the wordpress recipe; you can change its version, such as `mariadb:<version>`.');

        return;
    }

    throw new RuntimeException('Expected flight.yaml to be rejected.');
});

it('rejects a string that is not a type', function () {
    flightProject("services:\n  db: postgres:16\n");

    expect(fn () => project()->load())->toThrow(FlightException::class, 'Invalid "services.db.type"');
});

it('takes the type from the recipe when flight.yaml leaves it out', function () {
    flightProject(['recipe' => 'wordpress', 'services' => ['mariadb' => ['database' => 'shop']]]);

    expect(project()->services()['mariadb'])->toMatchArray(['type' => 'mariadb', 'database' => 'shop']);
});

it('rejects an invalid name', function () {
    flightProject(['name' => 'My App', 'app' => ['type' => 'php']]);

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
            'app' => ['type' => 'php', 'webroot' => 'public', 'extensions' => ['bcmath', 'exif', 'gd', 'intl']],
        ]);
});

it('overrides a recipe option by option', function () {
    flightProject(['recipe' => 'laravel', 'app' => ['type' => 'php:8.3', 'hostnames' => ['admin']]]);

    expect(project()->services()['app'])->toBe(['type' => 'php', 'version' => '8.3', 'webroot' => 'public', 'extensions' => ['bcmath', 'exif', 'gd', 'intl'], 'hostnames' => ['admin']]);
});

it('keeps the recipe app for an app without options', function () {
    flightProject(['recipe' => 'laravel', 'app' => ['type' => 'php:8.3']]);

    expect(project()->services()['app'])->toBe(['type' => 'php', 'version' => '8.3', 'webroot' => 'public', 'extensions' => ['bcmath', 'exif', 'gd', 'intl']]);
});

it('puts the app first, before the services', function () {
    flightProject(['services' => ['mariadb' => ['type' => 'mariadb']], 'app' => ['type' => 'php']]);

    expect(array_keys(project()->services()))->toBe(['app', 'mariadb']);
});

it('needs no app', function () {
    flightProject(['services' => ['mariadb' => ['type' => 'mariadb']]]);

    expect(project()->services())->not->toHaveKey('app');
});

it('replaces lists instead of merging them', function () {
    $merged = (fn () => $this->merge(
        ['php' => ['hostnames' => ['shop', 'api']]],
        ['php' => ['hostnames' => ['admin']]],
    ))->call(project());

    expect($merged)->toBe(['php' => ['hostnames' => ['admin']]]);
});

it('adds services next to the recipe app', function () {
    flightProject(['recipe' => 'laravel', 'services' => ['legacy' => ['type' => 'php']]]);

    expect(array_keys(project()->services()))->toBe(['app', 'legacy']);
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
    flightProject(['app' => ['type' => 'php'], 'provision' => $provision]);

    expect(fn () => project()->load())->toThrow(FlightException::class, "Invalid \"{$key}\"");
})->with([
    'not a list' => [['name' => 'Install'], 'provision'],
    'missing command' => [[['name' => 'Install', 'service' => 'php']], 'provision.0.run'],
    'unknown key' => [[['name' => 'Install', 'service' => 'php', 'run' => 'true', 'user' => 'root']], 'provision.0'],
]);

it('adds a queue worker with the laravel recipe queue option', function () {
    flightProject(['recipe' => ['laravel' => ['queue' => true]], 'app' => ['type' => 'php:8.3']]);

    expect(project()->services()['app'])->toBe([
        'type' => 'php',
        'version' => '8.3',
        'webroot' => 'public',
        'extensions' => ['bcmath', 'exif', 'gd', 'intl'],
        'workers' => ['queue' => 'php artisan queue:listen --tries=1 --timeout=0'],
    ]);
});

it('lets flight.yaml change and add workers next to the recipe ones', function () {
    flightProject(['recipe' => ['laravel' => ['queue' => true]], 'app' => ['type' => 'php', 'workers' => [
        'queue' => 'php artisan queue:work',
        'horizon' => 'php artisan horizon',
    ]]]);

    expect(project()->services()['app']['workers'])->toBe([
        'queue' => 'php artisan queue:work',
        'horizon' => 'php artisan horizon',
    ]);
});

it('adds no workers by default', function () {
    flightProject(['recipe' => 'laravel']);

    expect(project()->services()['app'])->not->toHaveKey('workers');
});

it('adds a scheduler with the laravel recipe scheduler option', function () {
    flightProject(['recipe' => ['laravel' => ['scheduler' => true]]]);

    expect(project()->services()['app']['workers'])->toBe(['scheduler' => 'php artisan schedule:work']);
});
