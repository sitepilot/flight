<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\FlightException;
use LaravelZero\Framework\Components\Updater\Updater;
use Phar;
use Throwable;

/**
 * Replaces the framework's own self-update command so that a failure reads
 * like every other error in Flight rather than a raw stack trace — the
 * likeliest failures here are being offline or behind a proxy, which is not
 * a bug worth a trace.
 */
class SelfUpdateCommand extends FlightCommand
{
    protected $signature = 'self-update';

    protected $description = 'Update Flight to the latest release';

    public function fly(): int
    {
        if (Phar::running() === '') {
            throw FlightException::make(
                'Self-update only works on the packaged binary.',
                'You are running Flight from source. Pull the repository instead, '.
                'or build a binary with: php flight app:build flight',
            );
        }

        $this->line('  Checking for a new version…');

        try {
            $this->laravel->make(Updater::class)->update($this->output);
        } catch (Throwable $e) {
            throw FlightException::make('Could not check for a new version.', $this->explain($e));
        }

        return self::SUCCESS;
    }

    /**
     * Versions come from Packagist, so the two failures worth naming are
     * "not published there yet" and "cannot reach it".
     */
    protected function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '404')) {
            return 'Flight is not published on Packagist yet, so there is nothing to compare against.';
        }

        if (str_contains($message, 'Failed to open stream') || str_contains($message, 'php_network')) {
            return 'Could not reach Packagist. Check your connection and try again.';
        }

        return $message;
    }
}
