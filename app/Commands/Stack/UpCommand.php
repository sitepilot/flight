<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Stacks\GlobalStack;
use App\Support\Certificate;
use App\Support\Compose;

class UpCommand extends StackCommand
{
    protected $signature = 'stack:up';

    protected $description = 'Start the Flight services, issuing a certificate when needed';

    public function handle(GlobalStack $stack, Compose $compose, Certificate $certificate): int
    {
        $this->step(sprintf(
            'Certificate %s for %s',
            $certificate->ensure() ? 'issued' : 'valid',
            $certificate->wildcard(),
        ));

        $this->composing(
            'Starting the stack',
            'Stack started',
            fn ($output) => $compose->up($stack, $output),
        );

        $this->stackSummary('Stack running', $stack);

        return self::SUCCESS;
    }
}
