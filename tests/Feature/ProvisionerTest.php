<?php

use App\Exceptions\FlightException;
use App\Provisioning\Provisioner;
use App\Stacks\ProjectStack;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\StubRecipe;

beforeEach(function () {
    flightDirectory();
    config(['flight.recipes.stub' => StubRecipe::class]);

    // Every command run, flattened to a string. The "test -f greeted"
    // check exits with $this->checkExitCode.
    $this->commands = new ArrayObject;
    $this->checkExitCode = 1;

    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);
        $this->commands[] = $command;

        return Process::result('', '', str_contains($command, 'test -f greeted') ? $this->checkExitCode : 0);
    });
});

function provisioner(): Provisioner
{
    return app(Provisioner::class);
}

/**
 * Run every step and return the names of those that ran.
 */
function provisionAll(): array
{
    $stack = app(ProjectStack::class);
    $ran = [];

    foreach (provisioner()->steps($stack) as $step) {
        if (provisioner()->run($stack, $step)) {
            $ran[] = $step->name;
        }
    }

    return $ran;
}

it('runs a step in its service when its check fails', function () {
    flightProject(['recipe' => 'stub']);

    expect(provisionAll())->toBe(['Greet', 'Always'])
        ->and($this->commands[0])->toEndWith('exec -T php sh -c test -f greeted')
        ->and($this->commands[1])->toEndWith('exec -T php sh -c echo hello https://myapp.flght.dev');
});

it('skips a step whose check passes', function () {
    flightProject(['recipe' => 'stub']);
    $this->checkExitCode = 0;

    expect(provisionAll())->toBe(['Always']);
});

it('always runs a step without a check', function () {
    flightProject(['recipe' => 'stub']);
    $this->checkExitCode = 0;

    provisionAll();

    $commands = $this->commands->getArrayCopy();

    expect(end($commands))->toEndWith('exec -T php sh -c true');
});

it('passes recipe options to its steps', function () {
    flightProject(['recipe' => ['stub' => ['greeting' => 'hi']]]);

    provisionAll();

    expect($this->commands[1])->toContain('echo hi');
});

it('runs the recipe steps before those in flight.yaml', function () {
    flightProject([
        'recipe' => 'stub',
        'provision' => [['name' => 'Mine', 'service' => 'php', 'run' => 'echo mine']],
    ]);

    $steps = provisioner()->steps(app(ProjectStack::class));

    expect(array_map(fn ($step) => $step->name, $steps))->toBe(['Greet', 'Always', 'Mine'])
        ->and($steps[2]->unless)->toBeNull();
});

it('reads the check of a step in flight.yaml', function () {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Install', 'service' => 'php', 'run' => 'composer install', 'unless' => 'test -d vendor'],
    ]]);

    [$step] = provisioner()->steps(app(ProjectStack::class));

    expect($step->command)->toBe('composer install')
        ->and($step->unless)->toBe('test -d vendor');
});

it('stops at a failing step, naming it', function () {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Break', 'service' => 'php', 'run' => 'exit 3'],
    ]]);

    Process::fake(fn () => Process::result('', 'boom', 3));

    provisionAll();
})->throws(FlightException::class, 'Step "Break" failed.');

it('rejects a step for a service the project does not have', function () {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Migrate', 'service' => 'db', 'run' => 'true'],
    ]]);

    provisioner()->steps(app(ProjectStack::class));
})->throws(FlightException::class, 'Step "Migrate"');

it('rejects an invalid recipe option, naming its path', function () {
    flightProject(['recipe' => ['stub' => ['greeting' => 'hey']]]);

    app(ProjectStack::class)->project()->load();
})->throws(FlightException::class, 'Invalid "recipe.stub.greeting"');

it('runs a step in a directory relative to the app', function (string $dir, string $prefix) {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Build', 'service' => 'php', 'run' => 'npm run build', 'unless' => 'test -f greeted', 'dir' => $dir],
    ]]);

    provisionAll();

    expect($this->commands[0])->toEndWith("exec -T php sh -c {$prefix} && test -f greeted")
        ->and($this->commands[1])->toEndWith("exec -T php sh -c {$prefix} && npm run build");
})->with([
    'a subdirectory' => ['wp-content/themes/my-theme', "cd 'wp-content/themes/my-theme'"],
    'the app itself' => ['.', "cd '.'"],
]);

it('runs a step in the container working directory without a dir', function () {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Build', 'service' => 'php', 'run' => 'npm run build'],
    ]]);

    provisionAll();

    expect($this->commands[0])->toEndWith('exec -T php sh -c npm run build');
});

it('rejects a step directory outside the app', function () {
    flightProject(['services' => ['php' => null], 'provision' => [
        ['name' => 'Build', 'service' => 'php', 'run' => 'true', 'dir' => '../etc'],
    ]]);

    app(ProjectStack::class)->project()->load();
})->throws(FlightException::class, 'Invalid "provision.0.dir"');
