<?php

declare(strict_types=1);

namespace App\Commands\Stack;

use App\Commands\FlightCommand;
use App\Support\Editor;
use App\Support\GlobalConfig;

class ConfigCommand extends FlightCommand
{
    protected $signature = 'stack:config';

    protected $description = 'Edit the Flight configuration in your editor';

    public function handle(GlobalConfig $config, Editor $editor): int
    {
        // Not load(): it would reject the invalid file the user wants to fix.
        $config->scaffold();

        $file = $config->file();

        $before = md5_file($file);

        $editor->open($file);

        $changed = md5_file($file) !== $before;

        // Validate even when unchanged, so a file that was already invalid
        // is reported now instead of on the next stack:up.
        $config->load();

        $this->step($changed ? 'Configuration is valid' : 'No changes made');

        if ($changed) {
            $this->note('Run `flight stack:restart` to apply your changes.');
        }

        return self::SUCCESS;
    }
}
