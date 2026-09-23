<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Stacks\ProjectStack;
use App\Support\GlobalConfig;

/**
 * What a recipe needs to know about the project to write its steps.
 */
class Context
{
    public function __construct(
        protected ProjectStack $stack,
        protected GlobalConfig $global,
    ) {}

    public function project(): string
    {
        return $this->stack->project()->name();
    }

    public function domain(): string
    {
        return $this->global->domain();
    }

    /**
     * Where the project is served, e.g. "https://myapp.flght.dev".
     */
    public function url(): string
    {
        foreach ($this->stack->services() as $service) {
            if ($service->hostnames() !== []) {
                return 'https://'.$service->hostnames()[0];
            }
        }

        return 'https://'.$this->project().'.'.$this->domain();
    }
}
