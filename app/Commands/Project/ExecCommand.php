<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Compose;

class ExecCommand extends ProjectCommand
{
    protected $signature = 'exec
        {--service= : The service to run it in, defaults to the first}
        {args* : The command to run; put it after -- when it has options}';

    protected $description = 'Run a command in one of the project\'s containers';

    protected bool $plain = true;

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        $service = $this->service($stack, $this->option('service'));

        return $compose->attach($stack, $service, $this->argument('args'), $this->passthrough())->exitCode() ?? self::FAILURE;
    }
}
