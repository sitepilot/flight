<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Compose;

class DownCommand extends ProjectCommand
{
    protected $signature = 'down';

    protected $description = 'Stop the project in the current directory';

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        // The global stack is shared with other projects and keeps running.
        $this->composing(
            'Stopping the project',
            'Project stopped',
            fn ($output) => $compose->down($stack, $output),
        );

        return self::SUCCESS;
    }
}
