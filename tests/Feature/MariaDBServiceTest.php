<?php

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Scaffold;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    flightDirectory();
});

function mariadbCompose(?array $options = null): array
{
    flightProject(['services' => ['mariadb' => $options]]);

    app(Scaffold::class)->write(app(ProjectStack::class));

    return Yaml::parseFile(getcwd().'/.flight/compose.yaml');
}

it('runs mariadb with its data in a named volume', function () {
    $compose = mariadbCompose();
    $mariadb = $compose['services']['mariadb'];

    expect($mariadb['image'])->toBe('mariadb:11.8')
        ->and($mariadb['environment'])->toBe([
            'MARIADB_DATABASE' => 'flight',
            'MARIADB_USER' => 'flight',
            'MARIADB_PASSWORD' => 'flight',
            'MARIADB_ROOT_PASSWORD' => 'flight',
        ])
        ->and($mariadb['volumes'])->toBe(['mariadb_data:/var/lib/mysql'])
        ->and($compose['volumes'])->toHaveKey('mariadb_data')
        ->and($mariadb)->not->toHaveKey('labels');
});

it('has a healthcheck so flight up waits until it accepts connections', function () {
    expect(mariadbCompose()['services']['mariadb']['healthcheck']['test'])
        ->toBe(['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']);
});

it('takes its version and credentials from its options', function () {
    $mariadb = mariadbCompose(['version' => 10.6, 'database' => 'shop', 'password' => 'secret'])['services']['mariadb'];

    expect($mariadb['image'])->toBe('mariadb:10.6')
        ->and($mariadb['environment']['MARIADB_DATABASE'])->toBe('shop')
        ->and($mariadb['environment']['MARIADB_PASSWORD'])->toBe('secret');
});

it('rejects an unsupported version', function () {
    expect(fn () => mariadbCompose(['version' => '5.5']))
        ->toThrow(FlightException::class, 'Invalid "services.mariadb.version"');
});

it('takes no workers', function () {
    expect(fn () => mariadbCompose(['workers' => ['backup' => 'sleep 1']]))
        ->toThrow(FlightException::class, 'Invalid "services.mariadb.workers"');
});
