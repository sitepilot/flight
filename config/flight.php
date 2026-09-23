<?php

use App\Recipes\Laravel;
use App\Recipes\Proxy;
use App\Recipes\WordPress;
use App\Services\MariaDB;
use App\Services\Php;
use App\Services\Traefik;
use App\Services\Valkey;

return [

    /*
    |--------------------------------------------------------------------------
    | Configuration Directory
    |--------------------------------------------------------------------------
    |
    | Holds config.yaml, the generated compose file, the Traefik configuration
    | and the certificates. Set FLIGHT_CONFIG_DIR to use another directory.
    |
    */

    'config_dir' => env('FLIGHT_CONFIG_DIR', ($_SERVER['HOME'] ?? '~').'/.config/flight'),

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Written to config.yaml on first run, and used for any key missing from
    | that file. Traefik's options are defaults of the Traefik service.
    |
    */

    'defaults' => [
        'domain' => 'flght.dev',
        'network' => 'flight',
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Recipe
    |--------------------------------------------------------------------------
    |
    | The recipe of the global stack. The services in config.yaml are merged
    | over it, just like a project's flight.yaml is merged over its recipe.
    |
    */

    'recipe' => 'proxy',

    /*
    |--------------------------------------------------------------------------
    | App Types
    |--------------------------------------------------------------------------
    |
    | The service types a project's `app` can have, such as `php:8.4`.
    |
    */

    'app_types' => ['php'],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | The service types config.yaml and flight.yaml can use, keyed by type
    | name, such as `db: mariadb:11.8`.
    |
    */

    'services' => [
        'traefik' => Traefik::class,
        'php' => Php::class,
        'mariadb' => MariaDB::class,
        'valkey' => Valkey::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recipes
    |--------------------------------------------------------------------------
    |
    | The preset stacks, keyed by name. A flight.yaml picks one with `recipe:`.
    |
    */

    'recipes' => [
        'proxy' => Proxy::class,
        'laravel' => Laravel::class,
        'wordpress' => WordPress::class,
    ],

];
