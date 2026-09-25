<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Compose;

class ListCommand extends FlightCommand
{
    protected $signature = 'list';

    protected $description = 'List the running projects';

    public function handle(Compose $compose): int
    {
        $projects = $compose->flightProjects();

        if ($projects === []) {
            $this->note('No projects are running.');

            return self::SUCCESS;
        }

        $width = max(11, ...array_map(fn (string $name): int => mb_strlen($name) + 2, array_keys($projects)));

        $rows = [];

        foreach ($projects as $name => $root) {
            $rows[] = [str_pad($name, $width), $this->displayPath($root)];
        }

        $this->panel('Running projects', $rows, 'cyan');

        $this->note('Use -p to run a command for one, e.g. `flight down -p '.array_key_first($projects).'`.');

        return self::SUCCESS;
    }
}
