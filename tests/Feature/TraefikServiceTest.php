<?php

use App\Exceptions\FlightException;
use App\Stacks\GlobalStack;

beforeEach(function () {
    flightDirectory();
});

it('defaults to the standard ports and docker socket', function () {
    $environment = app(GlobalStack::class)->environment();

    expect($environment)->toMatchArray([
        'FLIGHT_HTTP_PORT' => '80',
        'FLIGHT_HTTPS_PORT' => '443',
        'FLIGHT_DOCKER_SOCK' => '/var/run/docker.sock',
    ]);
});

it('rejects an option the validator will not accept', function (array $options, string $key) {
    flightConfig(['services' => ['traefik' => $options]]);

    expect(fn () => app(GlobalStack::class)->validate())
        ->toThrow(FlightException::class, "Invalid \"services.traefik.{$key}\"");
})->with([
    'port out of range' => [['http_port' => 99999], 'http_port'],
    'port that is not a number' => [['http_port' => 'abc'], 'http_port'],
    'empty docker socket' => [['docker_socket' => ''], 'docker_socket'],
]);

it('rejects identical http and https ports', function () {
    flightConfig(['services' => ['traefik' => ['http_port' => 443, 'https_port' => 443]]]);

    expect(fn () => app(GlobalStack::class)->validate())->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toContain('Invalid "services.traefik.https_port"')
            ->and($e->hint())->toContain('must be different');
    });
});

it('names the yaml path in the message, not a humanized one', function () {
    flightConfig(['services' => ['traefik' => ['http_port' => 99999]]]);

    expect(fn () => app(GlobalStack::class)->validate())->toThrow(function (FlightException $e) {
        // Laravel would say "http port".
        expect($e->hint())->toContain('services.traefik.http_port')
            ->and($e->hint())->not->toContain('http port');
    });
});

it('serves its dashboard at traefik.<domain>', function () {
    [$traefik] = app(GlobalStack::class)->services();

    expect($traefik->hostnames())->toBe(['traefik.flght.dev']);
});
