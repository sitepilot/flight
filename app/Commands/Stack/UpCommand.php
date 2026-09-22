<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Support\Certificate;

class UpCommand extends StackCommand
{
    protected $signature = 'stack:up';

    protected $description = 'Start the Flight services, issuing a certificate when needed';

    public function fly(Certificate $certificate): int
    {
        $this->step(sprintf(
            'Certificate %s for *.%s',
            $certificate->ensure() ? 'issued' : 'valid',
            $this->config->domain(),
        ));

        $this->composing(
            'Starting the stack',
            'Stack started',
            fn ($output) => $this->compose->up($this->stack, $output),
        );

        $this->summary('Stack running');

        return self::SUCCESS;
    }
}
