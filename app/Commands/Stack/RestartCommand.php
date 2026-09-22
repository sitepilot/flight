<?php

declare(strict_types=1);

namespace App\Commands\Stack;

class RestartCommand extends StackCommand
{
    protected $signature = 'stack:restart';

    protected $description = 'Recreate the Flight service containers';

    public function fly(): int
    {
        $this->recreate();

        return self::SUCCESS;
    }
}
