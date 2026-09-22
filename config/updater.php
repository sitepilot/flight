<?php

use App\Updater\ReleaseAssetStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Self-updater Strategy
    |--------------------------------------------------------------------------
    |
    | Versions are always discovered through Packagist, from the "name" in
    | composer.json. The strategy decides where the new binary is downloaded
    | from once a newer version is found.
    |
    */

    'strategy' => ReleaseAssetStrategy::class,

];
