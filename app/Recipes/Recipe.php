<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Provisioning\Context;
use App\Provisioning\Step;
use App\Support\HasOptions;
use App\Support\StackConfig;

/**
 * A preset stack for a kind of project, used with `recipe:` in flight.yaml.
 * The project's own services are merged over the recipe's. Options are set
 * in flight.yaml as `recipe: {<name>: {<option>: <value>}}`.
 */
abstract class Recipe
{
    use HasOptions;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(StackConfig $config, protected string $name, array $options = [])
    {
        $this->configure($config, "recipe.{$name}", $options);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Services shaped like the services section of flight.yaml. Set only the
     * options the project needs to work, and leave the rest, such as the
     * version, to the service defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function services(): array;

    /**
     * Steps run on every `flight up`, after the project has started. Give
     * each step an unless() check, so it is skipped once done.
     *
     * @return array<int, Step>
     */
    public function provision(Context $context): array
    {
        return [];
    }
}
