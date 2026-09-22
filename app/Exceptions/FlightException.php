<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

/**
 * An error we can explain to the user, with an optional line telling them
 * what to do about it. Anything else is a bug and should surface as a trace.
 */
class FlightException extends RuntimeException
{
    public function __construct(string $message, protected string $hint = '')
    {
        parent::__construct($message);
    }

    public static function make(string $message, string $hint = ''): self
    {
        return new self($message, $hint);
    }

    /**
     * Hint from whatever the failed command said, preferring stderr.
     */
    public static function fromProcess(ProcessResult $result, string $message, string $fallback = ''): self
    {
        return new self(
            $message,
            trim($result->errorOutput()) ?: trim($result->output()) ?: $fallback,
        );
    }

    public function hint(): string
    {
        return $this->hint;
    }
}
