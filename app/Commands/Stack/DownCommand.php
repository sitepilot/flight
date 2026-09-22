<?php

declare(strict_types=1);

namespace App\Commands\Stack;

class DownCommand extends StackCommand
{
    protected $signature = 'stack:down';

    protected $description = 'Stop the Flight services';

    public function fly(): int
    {
        $this->composing(
            'Stopping the stack',
            'Stack stopped',
            fn ($output) => $this->compose->down($this->stack, $output),
        );

        return self::SUCCESS;
    }
}
