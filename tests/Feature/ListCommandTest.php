<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Fake `docker compose ls` with the given stacks, as name ⇒ config files.
 */
function composeLs(array $stacks): void
{
    $json = json_encode(array_map(
        fn (string $name, array $files): array => ['Name' => $name, 'Status' => 'running(1)', 'ConfigFiles' => implode(',', $files)],
        array_keys($stacks),
        $stacks,
    ));

    Process::fake(fn ($process) => Process::result(in_array('ls', (array) $process->command, true) ? $json : ''));
}

beforeEach(function () {
    flightDirectory();
});

it('lists the running flight projects', function () {
    composeLs([
        'flight' => ['/home/me/.config/flight/.flight/compose.yaml'],
        // Compose lists the project's own files before Flight's.
        'myapp' => ['/home/me/code/myapp/compose.yaml', '/home/me/code/myapp/.flight/compose.yaml'],
        'other' => ['/home/me/code/other/compose.yaml'],
    ]);

    $this->withoutMockingConsoleOutput()->artisan('list');

    expect(Artisan::output())->toContain('myapp')
        ->toContain('/home/me/code/myapp')
        ->toContain('flight down -p myapp')
        ->not->toContain('other');
});

it('says when no project is running', function () {
    composeLs(['flight' => ['/home/me/.config/flight/.flight/compose.yaml']]);

    $this->withoutMockingConsoleOutput()->artisan('list');

    expect(Artisan::output())->toContain('No projects are running.');
});
