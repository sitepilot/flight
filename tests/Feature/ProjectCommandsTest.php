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
        ->and($commands[0])->toContain('-p flight -f '.$this->flightDirectory.'/.flight/compose.yaml')
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

it('runs the compose files listed under compose after its own', function () {
    file_put_contents($this->project.'/flight.yaml', "app: php\ncompose:\n  - path: compose.override.yml\n    required: false\n");
    file_put_contents($this->project.'/compose.override.yml', "services:\n  app:\n    ports: ['8080:8080']\n");

    $this->artisan('down')->assertExitCode(0);

    expect($this->commands[0])->toContain("-f {$this->project}/.flight/compose.yaml -f {$this->project}/compose.override.yml down");
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
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php:7.0\n");

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('up');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('app.type')
        ->toContain('Expected a php version, one of:');

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
        app:
          type: php
        provision:
          - name: Greet
            service: app
            run: echo hello
            unless: test -f greeted
        YAML);

    $this->withoutMockingConsoleOutput()->artisan('up');

    $commands = $this->commands->getArrayCopy();

    // Process::fake succeeds, so the check passes and the step is skipped.
    expect($commands[1])->toEndWith('up -d --wait')
        ->and($commands[2])->toEndWith('exec -T app sh -c test -f greeted')
        ->and($commands)->toHaveCount(3)
        ->and(Artisan::output())->toContain('Greet (skipped)');
});

it('destroys the project containers and volumes', function () {
    $this->artisan('destroy --force')->assertExitCode(0);

    expect($this->commands)->toHaveCount(1)
        ->and($this->commands[0])->toContain('-p flight-myapp')
        ->toEndWith('down --volumes --remove-orphans');
});

it('removes the data in .flight but keeps the user files', function () {
    mkdir($this->project.'/.flight/app/data', 0755, true);
    file_put_contents($this->project.'/.flight/app/data/index.php', '<?php');
    file_put_contents($this->project.'/.flight/.env', "TOKEN=secret\n");

    $this->withoutMockingConsoleOutput()->artisan('destroy --force');

    expect(Artisan::output())->toContain('Kept your .env in .flight.')
        ->and($this->project.'/.flight/app')->not->toBeDirectory()
        ->and($this->project.'/.flight/compose.yaml')->not->toBeFile()
        ->and($this->project.'/.flight/.env')->toBeFile()
        // Still keeps .env out of git.
        ->and($this->project.'/.flight/.gitignore')->toBeFile();
});

it('removes .flight entirely when it holds no user files', function () {
    $this->artisan('destroy --force')->assertExitCode(0);

    expect($this->project.'/.flight')->not->toBeDirectory()
        ->and($this->project.'/flight.yaml')->toBeFile();
});

it('asks before destroying', function () {
    mkdir($this->project.'/.flight/app/data', 0755, true);

    $this->artisan('destroy')
        ->expectsConfirmation("Remove myapp's containers, volumes and data? Its database and files in .flight are lost.", 'no')
        ->assertExitCode(0);

    Process::assertNothingRan();

    expect($this->project.'/.flight/app/data')->toBeDirectory();
});

it('destroys after confirming', function () {
    $this->artisan('destroy')
        ->expectsConfirmation("Remove myapp's containers, volumes and data? Its database and files in .flight are lost.", 'yes')
        ->assertExitCode(0);

    expect($this->commands[0])->toEndWith('down --volumes --remove-orphans');
});

it('opens a shell in the app', function () {
    $this->artisan('shell')->assertExitCode(0);

    expect($this->commands)->toHaveCount(1)
        ->and($this->commands[0])->toContain('-p flight-myapp')
        ->toMatch('/ exec( -T)? app sh -c if command -v bash/');
});

it('opens a shell in the service asked for', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  mariadb:\n    type: mariadb\n");

    $this->artisan('shell mariadb')->assertExitCode(0);

    expect($this->commands[0])->toMatch('/ exec( -T)? mariadb sh -c /');
});

it('names the services when the one asked for does not exist', function () {
    $exitCode = $this->withoutMockingConsoleOutput()->artisan('shell redis');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The project has no "redis" service.')
        ->toContain('Expected one of: app.');

    Process::assertNothingRan();
});

it('runs a command with its own options after --', function () {
    $this->artisan('exec -- php artisan migrate --force')->assertExitCode(0);

    expect($this->commands[0])->toMatch('/ exec( -T)? app php artisan migrate --force$/');
});

it('runs a command in another service', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  valkey:\n    type: valkey\n");

    $this->artisan('exec --service=valkey -- valkey-cli ping')->assertExitCode(0);

    expect($this->commands[0])->toMatch('/ exec( -T)? valkey valkey-cli ping$/');
});

it('exits with the exit code of the command', function () {
    Process::fake(fn () => Process::result('', 'failed', 3));

    $this->artisan('exec -- false')->assertExitCode(3);
});

it('prints only the command output, without the flight heading', function () {
    Process::fake(fn () => Process::result("PHP 8.4\n"));

    $this->withoutMockingConsoleOutput()->artisan('exec -- php -v');

    expect(Artisan::output())->not->toContain('Flight');
});

it('shows the logs of the app', function () {
    $this->artisan('logs')->assertExitCode(0);

    expect($this->commands)->toHaveCount(1)
        ->and($this->commands[0])->toContain('-p flight-myapp')
        ->toEndWith(' logs app');
});

it('shows the logs of the service asked for', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  mariadb:\n    type: mariadb\n");

    $this->artisan('logs mariadb')->assertExitCode(0);

    expect($this->commands[0])->toEndWith(' logs mariadb');
});

it('follows and tails the logs when asked', function () {
    $this->artisan('logs app -f --tail=50')->assertExitCode(0);

    expect($this->commands[0])->toEndWith(' logs --follow --tail 50 app');
});

it('rejects a tail that is not a number of lines', function () {
    $exitCode = $this->withoutMockingConsoleOutput()->artisan('logs --tail=many');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --tail.');

    Process::assertNothingRan();
});

it('rejects logs for a service the project does not have', function () {
    $exitCode = $this->withoutMockingConsoleOutput()->artisan('logs redis');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The project has no "redis" service.');
});

it('lists the services in the project summary', function () {
    file_put_contents($this->project.'/flight.yaml', "recipe:\n  laravel:\n    queue: true\nservices:\n  mariadb:\n    type: mariadb\n");

    $this->withoutMockingConsoleOutput()->artisan('up');

    // A row per service: its address, or else what it is.
    expect(Artisan::output())->toMatch('/│\s+app\s+https:\/\/myapp\.flght\.dev\s+│/')
        ->toMatch('/│\s+mariadb\s+MariaDB 11\.8 at mariadb:3306\s+│/')
        ->toMatch('/│\s+queue\s+php artisan queue:listen --tries=1 --timeout=0\s+│/');
});

it('shows the logs of a worker', function () {
    file_put_contents($this->project.'/flight.yaml', "recipe:\n  laravel:\n    queue: true\n");

    $this->artisan('logs queue')->assertExitCode(0);

    expect($this->commands[0])->toEndWith(' logs queue');
});
