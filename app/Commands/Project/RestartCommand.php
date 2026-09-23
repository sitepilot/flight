<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Compose;

class RestartCommand extends ProjectCommand
{
    protected $signature = 'restart';

    protected $description = 'Recreate the containers of the project in the current directory';

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        $this->composing(
            'Recreating the project',
            'Project recreated',
            fn ($output) => $compose->recreate($stack, $output),
        );

        $this->projectSummary('Project running', $stack);

        return self::SUCCESS;
    }
}
