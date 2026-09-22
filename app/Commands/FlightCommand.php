<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\FlightException;
use App\Support\GlobalConfig;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

abstract class FlightCommand extends Command
{
    protected GlobalConfig $config;

    /**
     * Collaborators arrive through method injection rather than the
     * constructor: command discovery instantiates every command during boot,
     * so a constructor-injected dependency would be captured before the
     * application had finished configuring itself.
     */
    public function handle(GlobalConfig $config): int
    {
        $this->config = $config;

        try {
            $this->heading();
            $this->bootstrap();

            // Dispatched through the container so each command declares the
            // collaborators it actually needs as fly() parameters.
            $status = (int) $this->laravel->call([$this, 'fly']);
        } catch (FlightException $e) {
            $this->renderFailure($e);

            $status = self::FAILURE;
        }

        // Every command closes with a blank line, mirroring the heading.
        $this->line('');

        return $status;
    }

    /**
     * Everything any command needs: a configuration file to work from.
     */
    protected function bootstrap(): void
    {
        $this->config->scaffold();
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
     * Draw a titled box. Termwind has no left/right borders, so the frame is
     * built here rather than with utility classes.
     *
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    protected function panel(string $title, array $rows, string $colour): void
    {
        $this->line('');

        // Inner width: the space between the two vertical border characters.
        $inner = mb_strlen($title) + 4;

        foreach ($rows as [$label, $value]) {
            $inner = max($inner, mb_strlen($label.$value) + 4);
        }

        $this->line(sprintf(
            '  <fg=%1$s>╭─ </><fg=%1$s;options=bold>%2$s</><fg=%1$s> %3$s╮</>',
            $colour,
            $title,
            str_repeat('─', $inner - mb_strlen($title) - 3)
        ));

        foreach ($rows as [$label, $value]) {
            $this->line(sprintf(
                '  <fg=%1$s>│</>  <fg=gray>%2$s</>%3$s%4$s<fg=%1$s>│</>',
                $colour,
                $label,
                $value,
                str_repeat(' ', $inner - mb_strlen($label.$value) - 2)
            ));
        }

        $this->line(sprintf('  <fg=%1$s>╰%2$s╯</>', $colour, str_repeat('─', $inner)));
    }

    /**
     * @return array<int, string>
     */
    protected function wrap(string $message, int $width): array
    {
        return explode("\n", wordwrap($message, $width, "\n", true));
    }

    /**
     * Shorten $HOME so the summary stays readable.
     */
    protected function displayPath(string $path): string
    {
        return Str::replaceStart($_SERVER['HOME'] ?? '', '~', $path);
    }
}
