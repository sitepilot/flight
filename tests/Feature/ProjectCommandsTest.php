<?php

use App\Stacks\ProjectStack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    flightDirectory();
    $this->project = flightProject();

    fakeValidCertificate();

    // Every command run, in order, flattened to a string.
    $this->commands = new ArrayObject;

    Process::fake(function ($process) {
        $this->commands[] = implode(' ', (array) $process->command);

        return Process::result('');
    });
});

it('starts the flight stack and then the project', function () {
    $this->artisan('up')->assertExitCode(0);

    $commands = $this->commands->getArrayCopy();

    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('-p flight -f '.$this->flightDirectory.'/compose.yaml')
        ->and($commands[0])->toEndWith('up -d --wait')
        ->and($commands[1])->toContain('--project-directory '.$this->project.' -p flight-myapp')
        ->and($commands[1])->toContain('-f '.$this->project.'/.flight/compose.yaml')
        ->and($commands[1])->toEndWith('up -d --wait');
});

it('shows the project url once running', function () {
    $this->withoutMockingConsoleOutput()->artisan('up');

    expect(Artisan::output())->toContain('https://myapp.flght.dev');
});

it('stops only the project', function () {
    $this->artisan('down')->assertExitCode(0);

    $commands = $this->commands->getArrayCopy();

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain('-p flight-myapp')
        ->and($commands[0])->toEndWith('down --remove-orphans');
});

it('recreates the project on restart', function () {
    $this->artisan('restart')->assertExitCode(0);

    expect($this->commands[0])->toContain('-p flight-myapp')
        ->toEndWith('up -d --remove-orphans --force-recreate');
});

it('includes the project override file when it exists', function () {
    mkdir($this->project.'/.flight');
    file_put_contents($this->project.'/.flight/compose.override.yaml', "services: {}\n");

    $this->artisan('down')->assertExitCode(0);

    expect($this->commands[0])->toContain('-f '.$this->project.'/.flight/compose.override.yaml');
});

it('keeps compose away from the application env file', function () {
    expect(app(ProjectStack::class)->environment())
        ->toMatchArray([
            'FLIGHT_DOMAIN' => 'flght.dev',
            'FLIGHT_PROJECT' => 'myapp',
            'COMPOSE_DISABLE_ENV_FILE' => '1',
        ]);
});

it('reports an invalid flight.yaml without running compose', function () {
    file_put_contents($this->project.'/flight.yaml', "services:\n  php:\n    version: '7.0'\n");

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('up');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('services.php.version');

    Process::assertNothingRan();
});

it('explains when there is no flight.yaml', function () {
    unlink($this->project.'/flight.yaml');

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('up');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No flight.yaml found');
});

it('serves a recipe project and names the recipe', function () {
    file_put_contents($this->project.'/flight.yaml', "recipe: laravel\n");

    $this->withoutMockingConsoleOutput()->artisan('up');

    expect(Artisan::output())->toContain('Recipe')
        ->toContain('laravel')
        ->toContain('https://myapp.flght.dev');
});

it('runs the provisioning steps after starting the project', function () {
    file_put_contents($this->project.'/flight.yaml', <<<'YAML'
        services:
          php: {}
        provision:
          - name: Greet
            service: php
            run: echo hello
            unless: test -f greeted
        YAML);

    $this->withoutMockingConsoleOutput()->artisan('up');

    $commands = $this->commands->getArrayCopy();

    // Process::fake succeeds, so the check passes and the step is skipped.
    expect($commands[1])->toEndWith('up -d --wait')
        ->and($commands[2])->toEndWith('exec -T php sh -c test -f greeted')
        ->and($commands)->toHaveCount(3)
        ->and(Artisan::output())->toContain('Greet (skipped)');
});
