<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Provisioning\Context;
use App\Provisioning\Step;
use App\Recipes\Recipe;

/**
 * A recipe with an option and two steps, one of which can be skipped.
 */
class StubRecipe extends Recipe
{
    protected function defaults(): array
    {
        return ['greeting' => 'hello'];
    }

    protected function rules(): array
    {
        return ['greeting' => ['required', 'in:hello,hi']];
    }

    public function app(): array
    {
        return ['type' => 'php'];
    }

    public function services(): array
    {
        return [];
    }

    public function provision(Context $context): array
    {
        return [
            Step::make('Greet')->run("echo {$this->option('greeting')} {$context->url()}")->unless('test -f greeted'),
            Step::make('Always')->run('true'),
        ];
    }
}
