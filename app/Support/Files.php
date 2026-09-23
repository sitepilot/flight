<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes files and directories, reporting failures as a FlightException
 * instead of a PHP warning.
 */
class Files
{
    public static function ensureDirectory(string $directory): void
    {
        // Check again after a failed mkdir, in case another process created
        // the directory in the meantime.
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw FlightException::make(
                "Could not create {$directory}.",
                'Check that you have permission to write there.',
            );
        }
    }

    public static function deleteDirectory(string $directory): void
    {
        if (! (new Filesystem)->deleteDirectory($directory)) {
            throw FlightException::make(
                "Could not remove {$directory}.",
                'Check that you have permission to delete it.',
            );
        }
    }

    public static function delete(string $path): void
    {
        if (! @unlink($path)) {
            throw FlightException::make(
                "Could not remove {$path}.",
                'Check that you have permission to delete it.',
            );
        }
    }

    public static function put(string $path, string $contents): void
    {
        static::ensureDirectory(dirname($path));

        if (@file_put_contents($path, $contents) === false) {
            throw FlightException::make(
                "Could not write {$path}.",
                'Check that you have permission to write there.',
            );
        }
    }
}
