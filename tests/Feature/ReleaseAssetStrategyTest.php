<?php

use App\Updater\ReleaseAssetStrategy;
use Humbug\SelfUpdate\Strategy\GithubStrategy;
use Humbug\SelfUpdate\Updater;

function strategy(): ReleaseAssetStrategy
{
    return new ReleaseAssetStrategy;
}

function updater(): Updater
{
    // Any existing file works, since nothing is updated.
    return new Updater(__FILE__, false);
}

it('downloads the asset the release workflow publishes', function () {
    expect(strategy()->getPharName())->toBe('flight');
});

it('compares versions with the tag prefix removed', function (string $tag) {
    $strategy = strategy();
    $strategy->setCurrentLocalVersion($tag);

    // The updater compares versions as strings, so a leftover "v" would
    // report an update on every check.
    expect($strategy->getCurrentLocalVersion(updater()))->toBe('1.0.1');
})->with(['unprefixed' => '1.0.1', 'prefixed' => 'v1.0.1']);

it('keeps the raw tag in the download url', function (string $tag) {
    $strategy = strategy();

    $base = new ReflectionClass(GithubStrategy::class);
    $base->getProperty('remoteVersion')->setValue($strategy, $tag);

    // The URL must still use the tag as GitHub created it, "v" included.
    expect($base->getMethod('getDownloadUrl')->invoke($strategy, [
        'source' => ['url' => 'https://github.com/sitepilot/flight.git'],
    ]))->toBe("https://github.com/sitepilot/flight/releases/download/{$tag}/flight");
})->with(['unprefixed' => '1.0.1', 'prefixed' => 'v1.0.1']);

it('asks packagist for the configured package', function () {
    $strategy = strategy();
    $strategy->setPackageName('sitepilot/flight');

    $method = new ReflectionMethod(GithubStrategy::class, 'getApiUrl');

    expect($method->invoke($strategy))
        ->toBe('https://repo.packagist.org/p2/sitepilot/flight.json');
});
