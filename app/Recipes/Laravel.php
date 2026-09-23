<?php

declare(strict_types=1);

namespace App\Recipes;

class Laravel extends Recipe
{
    /**
     * The workers the options add. Both start a fresh process for every job
     * or run, so they pick up code changes without a restart.
     */
    protected const array WORKERS = [
        'queue' => 'php artisan queue:listen --tries=1 --timeout=0',
        'scheduler' => 'php artisan schedule:work',
    ];

    protected function defaults(): array
    {
        return [
            'queue' => false,
            'scheduler' => false,
        ];
    }

    protected function rules(): array
    {
        return [
            'queue' => ['boolean'],
            'scheduler' => ['boolean'],
        ];
    }

    public function services(): array
    {
        $workers = array_filter(self::WORKERS, fn (string $name): bool => (bool) $this->option($name), ARRAY_FILTER_USE_KEY);

        return [
            'php' => [
                // Laravel is served from public/, whatever the PHP default.
                'webroot' => 'public',
                ...($workers === [] ? [] : ['workers' => $workers]),
            ],
        ];
    }
}
