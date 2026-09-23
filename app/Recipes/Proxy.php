<?php

declare(strict_types=1);

namespace App\Recipes;

/**
 * The global stack: the proxy that routes *.<domain> to projects.
 */
class Proxy extends Recipe
{
    public function services(): array
    {
        return [
            'traefik' => ['type' => 'traefik'],
        ];
    }
}
