<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Looks a binary up on PATH.
 *
 * Wraps Symfony's finder, which also understands Windows PATHEXT — relevant
 * here because under WSL the certificate is issued by mkcert.exe.
 */
class Executable
{
    public static function exists(string $binary): bool
    {
        // An explicit path is used as given; ExecutableFinder ignores any
        // name containing a separator.
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }
}
