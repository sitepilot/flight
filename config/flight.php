<?php

use App\Recipes\LaravelRecipe;
use App\Recipes\ProxyRecipe;
use App\Recipes\WordPressRecipe;
use App\Services\ComposeService;
use App\Services\MariaDBService;
use App\Services\PhpService;
use App\Services\TraefikService;
use App\Services\ValkeyService;

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

    'app_types' => ['php', 'compose'],

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
        'traefik' => TraefikService::class,
        'compose' => ComposeService::class,
        'php' => PhpService::class,
        'mariadb' => MariaDBService::class,
        'valkey' => ValkeyService::class,
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
        'proxy' => ProxyRecipe::class,
        'laravel' => LaravelRecipe::class,
        'wordpress' => WordPressRecipe::class,
    ],

];
