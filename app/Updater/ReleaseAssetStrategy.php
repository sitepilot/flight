<?php

declare(strict_types=1);

namespace App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
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
}
