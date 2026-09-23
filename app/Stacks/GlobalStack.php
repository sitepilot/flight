<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Support\GlobalConfig;
use Illuminate\Contracts\Container\Container;

/**
 * The shared services every project uses, such as the Traefik proxy. Lives
 * in ~/.config/flight.
 */
class GlobalStack extends Stack
{
    public function __construct(GlobalConfig $config, Container $container)
    {
        parent::__construct($config, $config, $container);
    }

    public function config(): GlobalConfig
    {
        return $this->config;
    }
}
