<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FlightException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Opens a file in the user's editor.
 */
class Editor
{
    /**
     * Tried in order when neither VISUAL nor EDITOR is set.
     */
    protected const array FALLBACKS = ['nano', 'vim', 'vi'];

    /**
     * The editor command, which may include arguments such as "code --wait".
     *
     * @return array<int, string>
     */
    public function command(): array
    {
        foreach (['VISUAL', 'EDITOR'] as $variable) {
            $configured = getenv($variable);

            if (is_string($configured) && trim($configured) !== '') {
                $command = preg_split('/\s+/', trim($configured));

                if (! Executable::exists($command[0])) {
                    throw FlightException::make(
                        "\${$variable} is set to \"{$command[0]}\", which was not found in your PATH.",
                        'Point it at an editor that is installed, or unset it to fall back to '.
                        implode(', ', self::FALLBACKS).'.',
                    );
                }

                return $command;
            }
        }

        foreach (self::FALLBACKS as $candidate) {
            if (Executable::exists($candidate)) {
                return [$candidate];
            }
        }

        throw FlightException::make(
            'No editor was found.',
            'Set $EDITOR to the command you want to use, for example: export EDITOR=nano',
        );
    }

    public function open(string $file): void
    {
        $command = [...$this->command(), $file];

        $process = Process::forever();

        // An interactive editor needs the terminal, but there is no TTY when
        // output is piped or in CI, and Symfony throws if we ask for one.
        if (SymfonyProcess::isTtySupported()) {
            $process = $process->tty();
        }

        // No timeout: the user may edit for as long as they like.
        $result = $process->run($command);

        if ($result->failed()) {
            throw FlightException::fromProcess(
                $result,
                'The editor exited with an error.',
                implode(' ', $command),
            );
        }
    }
}
