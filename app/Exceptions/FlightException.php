<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

/**
 * An error shown to the user, with an optional hint on how to fix it. Any
 * other exception is a bug and shows a stack trace.
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
     * Use the failed process's output as the hint, preferring stderr.
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
