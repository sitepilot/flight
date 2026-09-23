<?php

use App\Services\Php;
use App\Services\Traefik;

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
    | that file.
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
    | The services in the global stack, in the order they are written to the
    | compose file.
    |
    */

    'services' => [
        Traefik::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Services
    |--------------------------------------------------------------------------
    |
    | The service types a flight.yml can use, keyed by type name. A service's
    | type defaults to its name.
    |
    */

    'project_services' => [
        'php' => Php::class,
    ],

];
