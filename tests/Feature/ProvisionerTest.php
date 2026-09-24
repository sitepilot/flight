<?php

use App\Exceptions\FlightException;
use App\Provisioning\Provisioner;
use App\Provisioning\Step;
use App\Stacks\ProjectStack;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\StubRecipe;

afterEach(function () {
    putenv('COMPOSER_AUTH');
});

beforeEach(function () {
    flightDirectory();
    config(['flight.recipes.stub' => StubRecipe::class]);

    // Every command run, flattened to a string. The "test -f greeted"
    // check exits with $this->checkExitCode.
    $this->commands = new ArrayObject;
    $this->checkExitCode = 1;

    // The environment of each command, in the same order.
    $this->environments = new ArrayObject;

    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);
        $this->commands[] = $command;
        $this->environments[] = $process->environment;

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
        ->and($this->commands[0])->toEndWith('exec -T app sh -c test -f greeted')
        ->and($this->commands[1])->toEndWith('exec -T app sh -c echo hello https://myapp.flght.dev');
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

    expect(end($commands))->toEndWith('exec -T app sh -c true');
});

it('passes recipe options to its steps', function () {
    flightProject(['recipe' => ['stub' => ['greeting' => 'hi']]]);

    provisionAll();

    expect($this->commands[1])->toContain('echo hi');
});

it('runs the recipe steps before those in flight.yaml', function () {
    flightProject([
        'recipe' => 'stub',
        'provision' => [['name' => 'Mine', 'service' => 'app', 'run' => 'echo mine']],
    ]);

    $steps = provisioner()->steps(app(ProjectStack::class));

    expect(array_map(fn ($step) => $step->name, $steps))->toBe(['Greet', 'Always', 'Mine'])
        ->and($steps[2]->unless)->toBeNull();
});

it('reads the check of a step in flight.yaml', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Install', 'service' => 'app', 'run' => 'composer install', 'unless' => 'test -d vendor'],
    ]]);

    [$step] = provisioner()->steps(app(ProjectStack::class));

    expect($step->command)->toBe('composer install')
        ->and($step->unless)->toBe('test -d vendor');
});

it('stops at a failing step, naming it', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Break', 'service' => 'app', 'run' => 'exit 3'],
    ]]);

    Process::fake(fn () => Process::result('', 'boom', 3));

    provisionAll();
})->throws(FlightException::class, 'Step "Break" failed.');

it('rejects a step for a service the project does not have', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Migrate', 'service' => 'db', 'run' => 'true'],
    ]]);

    provisioner()->steps(app(ProjectStack::class));
})->throws(FlightException::class, 'Step "Migrate"');

it('rejects an invalid recipe option, naming its path', function () {
    flightProject(['recipe' => ['stub' => ['greeting' => 'hey']]]);

    app(ProjectStack::class)->project()->load();
})->throws(FlightException::class, 'Invalid "recipe.stub.greeting"');

it('runs a step in a directory relative to the app', function (string $dir, string $prefix) {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Build', 'service' => 'app', 'run' => 'npm run build', 'unless' => 'test -f greeted', 'dir' => $dir],
    ]]);

    provisionAll();

    expect($this->commands[0])->toEndWith("exec -T app sh -c {$prefix} && test -f greeted")
        ->and($this->commands[1])->toEndWith("exec -T app sh -c {$prefix} && npm run build");
})->with([
    'a subdirectory' => ['assets', "cd 'assets'"],
    'the app itself' => ['.', "cd '.'"],
]);

it('runs a step in the container working directory without a dir', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Build', 'service' => 'app', 'run' => 'npm run build'],
    ]]);

    provisionAll();

    expect($this->commands[0])->toEndWith('exec -T app sh -c npm run build');
});

it('rejects a step directory outside the app', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Build', 'service' => 'app', 'run' => 'true', 'dir' => '../etc'],
    ]]);

    app(ProjectStack::class)->project()->load();
})->throws(FlightException::class, 'Invalid "provision.0.dir"');

it('passes the variables a step needs by name only', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Install dependencies', 'service' => 'app', 'env' => ['COMPOSER_AUTH'], 'run' => 'composer install', 'unless' => 'test -f greeted'],
    ]]);
    putenv('COMPOSER_AUTH=secret-key');

    provisionAll();

    foreach ([0, 1] as $i) {
        expect($this->commands[$i])->toContain('exec -T -e COMPOSER_AUTH app sh -c')
            ->not->toContain('secret-key')
            ->and($this->environments[$i]['COMPOSER_AUTH'])->toBe('secret-key');
    }
});

it('reads a step variable from the project .env', function () {
    $root = flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Install dependencies', 'service' => 'app', 'env' => ['COMPOSER_AUTH'], 'run' => 'true'],
    ]]);
    file_put_contents($root.'/.env', "COMPOSER_AUTH=from-file\n");

    provisionAll();

    expect($this->environments[0]['COMPOSER_AUTH'])->toBe('from-file');
});

it('stops before anything runs when a step variable is not set', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Install dependencies', 'service' => 'app', 'env' => ['COMPOSER_AUTH'], 'run' => 'true'],
    ]]);

    expect(fn () => provisioner()->steps(app(ProjectStack::class)))->toThrow(function (FlightException $e) {
        expect($e->getMessage())->toBe('Step "Install dependencies" needs COMPOSER_AUTH.')
            ->and($e->hint())->toContain('/.env');
    });

    Process::assertNothingRan();
});

it('rejects an invalid variable name', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Install dependencies', 'service' => 'app', 'env' => ['COMPOSER-AUTH'], 'run' => 'true'],
    ]]);

    app(ProjectStack::class)->project()->load();
})->throws(FlightException::class, 'Invalid "provision.0.env.0"');

it('lets a recipe step declare its variables', function () {
    expect(Step::make('Install dependencies')->env('COMPOSER_AUTH', 'OTHER')->env)->toBe(['COMPOSER_AUTH', 'OTHER']);
});

it('runs a step in the app when it names no service', function () {
    flightProject(['app' => ['type' => 'php'], 'provision' => [
        ['name' => 'Migrate', 'run' => 'php artisan migrate'],
    ]]);

    provisionAll();

    expect($this->commands[0])->toEndWith('exec -T app sh -c php artisan migrate');
});

it('needs a service for a step when there is no app', function () {
    flightProject(['services' => ['mariadb' => ['type' => 'mariadb']], 'provision' => [
        ['name' => 'Migrate', 'run' => 'true'],
    ]]);

    provisioner()->steps(app(ProjectStack::class));
})->throws(FlightException::class, 'Step "Migrate" needs a command and one of the project\'s services.');
