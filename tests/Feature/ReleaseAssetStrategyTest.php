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
    // Any real file will do; nothing here performs an update.
    return new Updater(__FILE__, false);
}

it('downloads the asset the release workflow publishes', function () {
    expect(strategy()->getPharName())->toBe('flight');
});

it('compares versions with the tag prefix removed', function (string $tag) {
    $strategy = strategy();
    $strategy->setCurrentLocalVersion($tag);

    // The updater compares these with a plain string inequality, so a stray
    // "v" on one side would report an update on every single check.
    expect($strategy->getCurrentLocalVersion(updater()))->toBe('1.0.1');
})->with(['unprefixed' => '1.0.1', 'prefixed' => 'v1.0.1']);

it('keeps the raw tag in the download url', function (string $tag) {
    $strategy = strategy();

    $base = new ReflectionClass(GithubStrategy::class);
    $base->getProperty('remoteVersion')->setValue($strategy, $tag);

    // The URL has to match the tag GitHub actually created, prefix and all,
    // even though the comparison above drops it.
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
