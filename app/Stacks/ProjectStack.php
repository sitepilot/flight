<?php

declare(strict_types=1);

namespace App\Stacks;

use App\Support\GlobalConfig;
use App\Support\ProjectConfig;
use Illuminate\Contracts\Container\Container;

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
