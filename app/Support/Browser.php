<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Support\Facades\Process;

class Browser
{
    /**
     * The first command found is used. Under WSL, rundll32.exe hands the URL
     * to the Windows default browser, where mkcert's certificate authority is
     * trusted.
     */
    public function command(): array
    {
        $configured = getenv('BROWSER');

        if (is_string($configured) && trim($configured) !== '') {
            $command = preg_split('/\s+/', trim($configured));

            if (! Executable::exists($command[0])) {
                throw FlightException::make(
                    "\$BROWSER is set to \"{$command[0]}\", which was not found in your PATH.",
                    'Point it at a browser that is installed, or unset it to use your default browser.',
                );
            }

            return $command;
        }

        $candidates = match (true) {
            $this->isMac() => [['open']],
            $this->isWsl() => [['wslview'], ['rundll32.exe', 'url.dll,FileProtocolHandler']],
            default => [['xdg-open']],
        };

        foreach ($candidates as $command) {
            if (Executable::exists($command[0])) {
                return $command;
            }
        }

        throw FlightException::make(
            'No browser was found.',
            'Set $BROWSER to the command that opens a URL, for example: export BROWSER=firefox',
        );
    }

    public function open(string $url): void
    {
        $command = [...$this->command(), $url];

        $result = Process::timeout(30)->run($command);

        if ($result->failed()) {
            throw FlightException::fromProcess($result, "Could not open {$url}.", implode(' ', $command));
        }
    }

    public function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public function isWsl(): bool
    {
        $release = @file_get_contents('/proc/sys/kernel/osrelease');

        return is_string($release) && str_contains(strtolower($release), 'microsoft');
    }
}
