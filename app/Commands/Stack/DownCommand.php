<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Stacks\GlobalStack;
use App\Support\Compose;

class DownCommand extends StackCommand
{
    protected $signature = 'stack:down';

    protected $description = 'Stop the Flight services';

    public function handle(GlobalStack $stack, Compose $compose): int
    {
        $this->composing(
            'Stopping the stack',
            'Stack stopped',
            fn ($output) => $compose->down($stack, $output),
        );

        return self::SUCCESS;
    }
}
