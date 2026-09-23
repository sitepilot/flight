<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Stacks\GlobalStack;
use App\Support\Certificate;
use App\Support\Compose;

class SecureCommand extends StackCommand
{
    protected $signature = 'stack:secure';

    protected $description = 'Regenerate the wildcard certificate and restart the stack';

    public function handle(GlobalStack $stack, Compose $compose, Certificate $certificate): int
    {
        $certificate->issue();

        $this->step('Certificate issued for '.$certificate->wildcard());

        $this->recreate($stack, $compose);

        $this->note(sprintf(
            'If this is a first run, restart your browser so it picks up the mkcert root CA. '.
            'Every *.%s hostname must resolve to 127.0.0.1.',
            $stack->config()->domain()
        ));

        return self::SUCCESS;
    }
}
