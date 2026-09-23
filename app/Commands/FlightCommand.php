<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\FlightException;
use App\Stacks\Stack;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\spin;

/**
 * Wraps every command in a heading and renders a FlightException as an
 * error panel instead of a stack trace.
 *
 * Commands take their dependencies as handle() parameters, not constructor
 * parameters: commands are instantiated during boot, before the application
 * is fully configured.
 */
abstract class FlightCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->heading();

        try {
            $status = parent::execute($input, $output);
        } catch (FlightException $e) {
            $this->renderFailure($e);

            $status = self::FAILURE;
        }

        $this->line('');

        return $status;
    }

    protected function heading(): void
    {
        $this->line('');
        $this->line(sprintf(
            '  <fg=cyan;options=bold>✈  Flight</> <fg=gray>%s</>',
            config('app.version')
        ));
        $this->line('');
    }

    protected function step(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }

    protected function note(string $message): void
    {
        $this->line('');

        foreach ($this->wrap($message, 66) as $line) {
            $this->line("  <fg=gray>{$line}</>");
        }
    }

    protected function skipped(string $message): void
    {
        $this->line("  <fg=gray>– {$message}</>");
    }

    /**
     * Run an action behind a spinner, or stream its output with -v.
     */
    protected function running(string $message, callable $action): mixed
    {
        if ($this->output->isVerbose()) {
            $this->line('');

            return $action(fn ($type, $buffer) => $this->output->write($buffer));
        }

        return spin(fn () => $action(null), $message.'…');
    }

    protected function composing(string $running, string $done, callable $action): void
    {
        $this->running($running, $action);

        $this->step($done);
    }

    /**
     * A panel with the given rows around one row per URL the stack serves.
     *
     * @param  array<int, array{0: string, 1: string}>  $before
     * @param  array<int, array{0: string, 1: string}>  $after
     */
    protected function summary(string $title, Stack $stack, array $before, array $after): void
    {
        $rows = $before;

        foreach ($stack->services() as $service) {
            foreach ($service->hostnames() as $hostname) {
                $rows[] = [ucfirst($service->name()), 'https://'.$hostname];
            }
        }

        $rows = [...$rows, ...$after];

        $this->panel($title, array_map(
            fn (array $row): array => [str_pad($row[0], 11), $row[1]],
            $rows,
        ), 'cyan');
    }

    protected function renderFailure(FlightException $e): void
    {
        $rows = array_map(fn (string $line): array => ['', $line], $this->wrap($e->getMessage(), 60));

        if ($e->hint() !== '') {
            $rows[] = ['', ''];

            foreach ($this->wrap($e->hint(), 60) as $line) {
                $rows[] = ['', $line];
            }
        }

        $this->panel('Error', $rows, 'red');
    }

    /**
     * Drawn by hand because Termwind has no left or right borders.
     *
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    protected function panel(string $title, array $rows, string $color): void
    {
        $this->line('');

        // The width between the two vertical borders.
        $inner = mb_strlen($title) + 4;

        foreach ($rows as [$label, $value]) {
            $inner = max($inner, mb_strlen($label.$value) + 4);
        }

        $this->line(sprintf(
            '  <fg=%1$s>╭─ </><fg=%1$s;options=bold>%2$s</><fg=%1$s> %3$s╮</>',
            $color,
            $title,
            str_repeat('─', $inner - mb_strlen($title) - 3)
        ));

        foreach ($rows as [$label, $value]) {
            $this->line(sprintf(
                '  <fg=%1$s>│</>  <fg=gray>%2$s</>%3$s%4$s<fg=%1$s>│</>',
                $color,
                $label,
                $value,
                str_repeat(' ', $inner - mb_strlen($label.$value) - 2)
            ));
        }

        $this->line(sprintf('  <fg=%1$s>╰%2$s╯</>', $color, str_repeat('─', $inner)));
    }

    /**
     * @return array<int, string>
     */
    protected function wrap(string $message, int $width): array
    {
        // A trailing space would push the panel's right border out of line.
        return array_map('trim', explode("\n", wordwrap($message, $width, "\n", true)));
    }

    protected function displayPath(string $path): string
    {
        return Str::replaceStart($_SERVER['HOME'] ?? '', '~', $path);
    }
}
