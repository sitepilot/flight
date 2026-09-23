<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Exceptions\FlightException;
use App\Stacks\ProjectStack;
use App\Support\Compose;

class LogsCommand extends ProjectCommand
{
    protected $signature = 'logs
        {service? : The service to show logs for, defaults to the app}
        {--f|follow : Keep showing new logs}
        {--tail= : Only show this many of the latest lines}';

    protected $description = 'Show the logs of one of the project\'s containers';

    protected bool $plain = true;

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        $service = $this->service($stack, $this->argument('service'));
        $tail = $this->option('tail');

        if ($tail !== null && ! ctype_digit((string) $tail)) {
            throw FlightException::make('Invalid --tail.', 'Expected a number of lines, such as --tail=100.');
        }

        return $compose->logs($stack, $service, (bool) $this->option('follow'), $tail, $this->passthrough())->exitCode() ?? self::FAILURE;
    }
}
