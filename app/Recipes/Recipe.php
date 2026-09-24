<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Provisioning\Context;
use App\Support\HasOptions;
use App\Support\StackConfig;

/**
 * A preset stack for a kind of project, used with `recipe:` in flight.yaml.
 * The project's app and services are merged over the recipe's.
 */
abstract class Recipe
{
    use HasOptions;

    public function __construct(StackConfig $config, protected string $name, array $options = [])
    {
        $this->configure($config, "recipe.{$name}", $options);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function app(): ?array
    {
        return null;
    }

    /**
     * Set only the options the project needs to work, and leave the rest,
     * such as the version, to the service's defaults.
     */
    abstract public function services(): array;

    /**
     * Run on every `flight up`, after the project has started. Give each step
     * an unless() check, so it's skipped once done.
     */
    public function provision(Context $context): array
    {
        return [];
    }
}
