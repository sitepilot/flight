<?php

declare(strict_types=1);

namespace App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;

/**
 * Downloads the phar attached to a GitHub release.
 *
 * Laravel Zero's default strategy downloads the binary from the repository,
 * which would mean committing a 12 MB phar for every release. Its strategies
 * are final, so this extends Humbug's strategy and sets the asset name that
 * Laravel Zero never provides.
 */
class ReleaseAssetStrategy extends GithubStrategy implements StrategyInterface
{
    /**
     * Fixed rather than read from the running phar, since the user may have
     * renamed their copy.
     */
    public function getPharName(): string
    {
        return 'flight';
    }

    /**
     * Drop the "v" from tags like "v1.0.1". The updater compares versions as
     * strings, so "v1.0.1" would never equal the built "1.0.1" and every
     * check would download the same binary again. The download URL still
     * uses the original tag.
     */
    public function getCurrentRemoteVersion(Updater $updater)
    {
        return self::normalize(parent::getCurrentRemoteVersion($updater));
    }

    public function getCurrentLocalVersion(Updater $updater)
    {
        return self::normalize(parent::getCurrentLocalVersion($updater));
    }

    private static function normalize(?string $version): ?string
    {
        return $version === null ? null : preg_replace('/^v/', '', $version);
    }
}
