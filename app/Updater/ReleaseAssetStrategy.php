<?php

declare(strict_types=1);

namespace App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;

/**
 * Downloads the phar attached to a GitHub release.
 *
 * Laravel Zero's own strategies are final, and its default one fetches the
 * binary from `raw/<tag>/builds/flight`, which would mean committing a 12 MB
 * phar on every release. This subclasses Humbug's strategy directly so the
 * asset can come from the release instead, which its base class supports but
 * never gets told the filename for — nothing in Laravel Zero calls
 * setPharName(), so the download URL would otherwise end in an empty name.
 */
class ReleaseAssetStrategy extends GithubStrategy implements StrategyInterface
{
    /**
     * The asset name published by the release workflow. Deliberately fixed
     * rather than read from the running phar: the user is free to rename
     * their local copy, but the published asset is always "flight".
     */
    public function getPharName(): string
    {
        return 'flight';
    }

    /**
     * Packagist reports whatever the tag is called, so a `v1.0.1` release
     * comes back as "v1.0.1" while the binary was built as "1.0.1".
     *
     * The updater compares the two with a plain string inequality, so an
     * unnormalised prefix means every check reports an update and downloads
     * the same binary again. The parent has already built the download URL
     * from the raw tag by the time this returns, so the prefix is only
     * dropped from the value used for that comparison.
     */
    public function getCurrentRemoteVersion(Updater $updater)
    {
        return self::normalise(parent::getCurrentRemoteVersion($updater));
    }

    public function getCurrentLocalVersion(Updater $updater)
    {
        return self::normalise(parent::getCurrentLocalVersion($updater));
    }

    private static function normalise(?string $version): ?string
    {
        return $version === null ? null : preg_replace('/^v/', '', $version);
    }
}
