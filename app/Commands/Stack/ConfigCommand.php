<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Commands\FlightCommand;
use App\Support\Editor;

/**
 * Extends FlightCommand rather than StackCommand: editing has to work when
 * Docker is down and, more importantly, when the configuration is currently
 * invalid — the stack preconditions would validate it and refuse to open the
 * very file the user needs to fix.
 */
class ConfigCommand extends FlightCommand
{
    protected $signature = 'stack:config';

    protected $description = 'Edit the Flight configuration in your editor';

    public function fly(Editor $editor): int
    {
        $file = $this->config->file();

        $before = md5_file($file);

        $editor->open($file);

        $changed = md5_file($file) !== $before;

        // Validated whether or not anything changed: a file that was already
        // broken is still broken, and saying "no changes made" and exiting 0
        // would imply it is fine. Reports now rather than on the next
        // stack:up.
        $this->config->load();

        $this->step($changed ? 'Configuration is valid' : 'No changes made');

        if ($changed) {
            $this->note('Run `flight stack:restart` to apply your changes.');
        }

        return self::SUCCESS;
    }
}
