<?php

declare(strict_types=1);

namespace App\Recipes;

/**
 * A preset stack for a kind of project, used with `recipe:` in flight.yaml.
 * The project's own services are merged over the recipe's. There is no
 * constructor, so each recipe can inject what it needs.
 */
abstract class Recipe
{
    /**
     * Services shaped like the services section of flight.yaml. Set only the
     * options the project needs to work, and leave the rest, such as the
     * version, to the service defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function services(): array;
}
