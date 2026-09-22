<?php

use App\Services\Traefik;

return [

    /*
    |--------------------------------------------------------------------------
    | Configuration Directory
    |--------------------------------------------------------------------------
    |
    | Everything Flight owns lives here: the user's config.yaml, the generated
    | compose file, the Traefik dynamic configuration and the certificates.
    | Override it with FLIGHT_CONFIG_DIR to run against a scratch directory.
    |
    */

    'config_dir' => env('FLIGHT_CONFIG_DIR', ($_SERVER['HOME'] ?? '~').'/.config/flight'),

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Seeded into config.yaml on first run and used as the fallback for any
    | key the user has removed from that file.
    |
    */

    'defaults' => [
        'domain' => 'flght.dev',
        'network' => 'flight',
        'http_port' => 80,
        'https_port' => 443,
        'docker_socket' => '/var/run/docker.sock',
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Services
    |--------------------------------------------------------------------------
    |
    | The services that make up the global stack, merged into the generated
    | compose file in this order. Add a class here to ship a new service.
    |
    */

    'services' => [
        Traefik::class,
    ],

];
