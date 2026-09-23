<?php

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Scaffold;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    flightDirectory();
});

function valkeyCompose(?array $options = null): array
{
    flightProject(['services' => ['valkey' => $options]]);

    app(Scaffold::class)->write(app(ProjectStack::class));

    return Yaml::parseFile(getcwd().'/.flight/compose.yaml');
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

it('accepts an unquoted version', function () {
    expect(valkeyCompose(['version' => 8.0])['services']['valkey']['image'])->toBe('valkey/valkey:8.0');
});

it('rejects an unsupported version', function () {
    expect(fn () => valkeyCompose(['version' => '6.0']))
        ->toThrow(FlightException::class, 'Invalid "services.valkey.version"');
});
