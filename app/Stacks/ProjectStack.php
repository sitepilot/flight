<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Support\GlobalConfig;
use App\Support\ProjectConfig;
use Illuminate\Contracts\Container\Container;

/**
 * The services a project's flight.yml asks for. Generated files go in
 * .flight, but the project root is the compose project directory.
 */
class ProjectStack extends Stack
{
    public function __construct(ProjectConfig $project, GlobalConfig $global, Container $container)
    {
        parent::__construct($project, $global, $container);
    }

    public function project(): ProjectConfig
    {
        return $this->config;
    }
}
