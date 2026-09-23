<?php

use App\Exceptions\FlightException;
use App\Support\ProjectConfig;
use App\Support\Variables;

beforeEach(function () {
    flightDirectory();
    $this->root = flightProject();
    mkdir($this->root.'/.flight');
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

it('prefers the project .flight/.env over the global one', function () {
    file_put_contents($this->flightDirectory.'/.env', "FLIGHT_TEST_KEY=global\n");
    file_put_contents($this->root.'/.flight/.env', "FLIGHT_TEST_KEY=\"project value\"\n");

    expect(variable('FLIGHT_TEST_KEY'))->toBe('project value');
});

it('prefers the shell over both files', function () {
    file_put_contents($this->root.'/.flight/.env', "FLIGHT_TEST_KEY=project\n");
    putenv('FLIGHT_TEST_KEY=shell');

    expect(variable('FLIGHT_TEST_KEY'))->toBe('shell');
});

it('returns null for a variable set nowhere', function () {
    expect(variable('FLIGHT_TEST_KEY'))->toBeNull();
});

it('never reads the project .env, which belongs to the app', function () {
    file_put_contents($this->root.'/.env', "FLIGHT_TEST_KEY=app\n");

    expect(variable('FLIGHT_TEST_KEY'))->toBeNull();
});

it('says where to set a variable', function () {
    expect(app(Variables::class)->hint(app(ProjectConfig::class)))
        ->toContain($this->root.'/.flight/.env')
        ->toContain($this->flightDirectory.'/.env')
        ->toContain('or in your shell');
});

it('reports an unparsable .env', function () {
    file_put_contents($this->root.'/.flight/.env', "FLIGHT TEST KEY=value\n");

    variable('FLIGHT_TEST_KEY');
})->throws(FlightException::class, 'Could not parse');
