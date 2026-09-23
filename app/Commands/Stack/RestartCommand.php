<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Stacks\GlobalStack;
use App\Support\Compose;

class RestartCommand extends StackCommand
{
    protected $signature = 'stack:restart';

    protected $description = 'Recreate the Flight service containers';

    public function handle(GlobalStack $stack, Compose $compose): int
    {
        $this->recreate($stack, $compose);

        return self::SUCCESS;
    }
}
