<?php

use App\Exceptions\FlightException;

beforeEach(function () {
    flightDirectory();
});

function valkeyCompose(array $options = []): array
{
    $type = isset($options['version']) ? "valkey:{$options['version']}" : 'valkey';
    unset($options['version']);

    flightProject(['services' => ['valkey' => ['type' => $type, ...$options]]]);

    return writeProjectCompose();
}

it('runs valkey with its data in a named volume', function () {
    $compose = valkeyCompose();
    $valkey = $compose['services']['valkey'];

    expect($valkey['image'])->toBe('valkey/valkey:9.1')
        ->and($valkey['volumes'])->toBe(['valkey_data:/data'])
        ->and($compose['volumes'])->toHaveKey('valkey_data')
        ->and($valkey)->not->toHaveKey('labels');
});

it('has a healthcheck so flight up waits until it answers', function () {
    expect(valkeyCompose()['services']['valkey']['healthcheck']['test'])
        ->toBe(['CMD-SHELL', 'valkey-cli ping | grep -q PONG']);
});

it('reads the version from the type', function () {
    expect(valkeyCompose(['version' => '8.0'])['services']['valkey']['image'])->toBe('valkey/valkey:8.0');
});

it('rejects an unsupported version', function () {
    expect(fn () => valkeyCompose(['version' => '6.0']))
        ->toThrow(FlightException::class, 'Invalid "services.valkey.type"');
});
