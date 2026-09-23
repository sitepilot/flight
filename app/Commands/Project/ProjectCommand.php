<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Commands\FlightCommand;
use App\Stacks\ProjectStack;

/**
 * Output shared by the commands that manage the project in the current
 * directory.
 */
abstract class ProjectCommand extends FlightCommand
{
    protected function projectSummary(string $title, ProjectStack $stack): void
    {
        $this->summary($title, $stack, [
            ['Project', $stack->project()->name()],
        ], [
            ['Directory', $this->displayPath($stack->directory())],
        ]);
    }
}
