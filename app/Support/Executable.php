<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds a binary on PATH, including Windows binaries such as mkcert.exe under
 * WSL.
 */
class Executable
{
    public static function exists(string $binary): bool
    {
        // ExecutableFinder ignores names that contain a path separator.
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }
}
