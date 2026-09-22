<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Support\Certificate;

class SecureCommand extends StackCommand
{
    protected $signature = 'stack:secure';

    protected $description = 'Regenerate the wildcard certificate and restart the stack';

    public function fly(Certificate $certificate): int
    {
        $certificate->issue();
        $this->step('Certificate issued for *.'.$this->config->domain());

        $this->recreate();

        $this->note(sprintf(
            'If this is a first run, restart your browser so it picks up the mkcert root CA. '.
            'Every *.%s hostname must resolve to 127.0.0.1.',
            $this->config->domain()
        ));

        return self::SUCCESS;
    }
}
