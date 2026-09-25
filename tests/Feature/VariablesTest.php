<?php

use App\Exceptions\FlightException;
use App\Support\ProjectConfig;
use App\Support\Variables;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    flightDirectory();
    $this->root = flightProject();
    Process::fake(fn ($process) => composeConfig($process) ?? Process::result(''));
});

afterEach(function () {
    putenv('FLIGHT_TEST_KEY');
});

function variable(string $name): ?string
{
    return app(Variables::class)->get(app(ProjectConfig::class), $name);
}

it('reads a variable from the global .env', function () {
    file_put_contents($this->flightDirectory.'/.env', "FLIGHT_TEST_KEY=global\n");

    expect(variable('FLIGHT_TEST_KEY'))->toBe('global');
});

it('prefers the project .env over the global one', function () {
    file_put_contents($this->flightDirectory.'/.env', "FLIGHT_TEST_KEY=global\n");
    file_put_contents($this->root.'/.env', "FLIGHT_TEST_KEY=\"project value\"\n");

    expect(variable('FLIGHT_TEST_KEY'))->toBe('project value');
});

it('prefers the shell over both files', function () {
    file_put_contents($this->root.'/.env', "FLIGHT_TEST_KEY=project\n");
    putenv('FLIGHT_TEST_KEY=shell');

    expect(variable('FLIGHT_TEST_KEY'))->toBe('shell');
});

it('returns null for a variable set nowhere', function () {
    expect(variable('FLIGHT_TEST_KEY'))->toBeNull();
});

it('reads the .env next to the first compose file, as compose does', function () {
    file_put_contents($this->root.'/flight.yaml', "app: php\ncompose: [.docker/compose.yml]\n");
    mkdir($this->root.'/.docker');
    file_put_contents($this->root.'/.docker/compose.yml', "services: {}\n");
    file_put_contents($this->root.'/.env', "FLIGHT_TEST_KEY=root\n");
    file_put_contents($this->root.'/.docker/.env', "FLIGHT_TEST_KEY=docker\n");

    expect(variable('FLIGHT_TEST_KEY'))->toBe('docker');
});

it('warns about a project .env git does not ignore, once a step reads it', function (int $exitCode, bool $warns) {
    file_put_contents($this->root.'/.env', "FLIGHT_TEST_KEY=project\n");
    Process::fake(fn () => Process::result(exitCode: $exitCode));

    $variables = app(Variables::class);
    $config = app(ProjectConfig::class);

    expect($variables->unignoredProjectFile($config))->toBeNull();

    $variables->get($config, 'FLIGHT_TEST_KEY');

    expect($variables->unignoredProjectFile($config))->toBe($warns ? $this->root.'/.env' : null);
})->with([
    'not ignored' => [1, true],
    'ignored' => [0, false],
    'no repository' => [128, false],
]);

it('says where to set a variable', function () {
    expect(app(Variables::class)->hint(app(ProjectConfig::class)))
        ->toContain($this->root.'/.env')
        ->toContain($this->flightDirectory.'/.env')
        ->toContain('or in your shell');
});

it('reports an unparsable .env', function () {
    file_put_contents($this->root.'/.env', "FLIGHT TEST KEY=value\n");

    variable('FLIGHT_TEST_KEY');
})->throws(FlightException::class, 'Could not parse');
