<?php

declare(strict_types=1);

namespace App\Recipes;

class Laravel extends Recipe
{
    public function services(): array
    {
        return [
            // Laravel is served from public/, whatever the PHP default.
            'php' => ['webroot' => 'public'],
        ];
    }
}
