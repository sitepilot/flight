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
    protected const FALLBACKS = ['nano', 'vim', 'vi'];

    /**
     * The editor command, which may carry arguments of its own such as
     * "code --wait".
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

        // Handing over the terminal is what makes an interactive editor
        // usable, but /dev/tty is not always there — piped output, CI, a
        // scripted $EDITOR. Symfony throws outright in that case, so only ask
        // for a TTY when one is actually available.
        if (SymfonyProcess::isTtySupported()) {
            $process = $process->tty();
        }

        // No timeout: the user decides how long they spend in there.
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
