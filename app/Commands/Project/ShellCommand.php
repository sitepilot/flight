<?php

declare(strict_types=1);

namespace App\Commands\Project;

use App\Stacks\ProjectStack;
use App\Support\Compose;

class ShellCommand extends ProjectCommand
{
    protected $signature = 'shell {service? : The service to open a shell in, defaults to the first}';

    protected $description = 'Open a shell in one of the project\'s containers';

    protected bool $plain = true;

    public function handle(ProjectStack $stack, Compose $compose): int
    {
        $service = $this->service($stack, $this->argument('service'));

        // Prefer bash, which not every image has.
        return $compose->attach($stack, $service, [
            'sh', '-c', 'if command -v bash >/dev/null 2>&1; then exec bash; else exec sh; fi',
        ], $this->passthrough())->exitCode() ?? self::FAILURE;
    }
}
