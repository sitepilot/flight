<?php

use App\Stacks\GlobalStack;
use App\Support\GlobalConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Assert that a compose command ran with the given trailing arguments.
 */
function assertComposeRan(string $endsWith, ?callable $and = null): void
{
    Process::assertRan(function ($process) use ($endsWith, $and) {
        $command = implode(' ', (array) $process->command);

        return str_starts_with($command, 'docker compose --project-directory')
            && str_contains($command, '-p flight')
            && str_ends_with($command, $endsWith)
            && ($and === null || $and($command));
    });
}

beforeEach(function () {
    flightDirectory();
    fakeValidCertificate();

    Process::fake();
});

it('starts the stack with the ported up flags', function () {
    $this->artisan('stack:up')->assertExitCode(0);

    assertComposeRan('up -d --wait', fn ($command) => str_contains($command, '--project-directory '.$this->flightDirectory)
        && str_contains($command, '-f '.$this->flightDirectory.'/compose.yaml'));
});

it('stops the stack removing orphans', function () {
    $this->artisan('stack:down')->assertExitCode(0);

    assertComposeRan('down --remove-orphans');
});

it('recreates the stack on restart', function () {
    $this->artisan('stack:restart')->assertExitCode(0);

    assertComposeRan('up -d --remove-orphans --force-recreate');
});

it('reissues the certificate and restarts on secure', function () {
    fakeValidCertificate()->shouldReceive('issue')->once();

    $this->artisan('stack:secure')->assertExitCode(0);

    assertComposeRan('up -d --remove-orphans --force-recreate');
});

it('leaves the override file out when there is none', function () {
    $this->artisan('stack:up')->assertExitCode(0);

    assertComposeRan('up -d --wait', fn ($command) => ! str_contains($command, 'compose.override.yaml'));
});

it('includes the override file when it exists', function () {
    file_put_contents($this->flightDirectory.'/compose.override.yaml', "services: {}\n");

    $this->artisan('stack:up')->assertExitCode(0);

    assertComposeRan('up -d --wait', fn ($command) => str_contains(
        $command,
        '-f '.$this->flightDirectory.'/compose.override.yaml'
    ));
});

it('spawns no docker process beyond compose itself', function () {
    $this->artisan('stack:up')->assertExitCode(0);

    // Checking these first would add about 160ms to every command.
    Process::assertDidntRun(['docker', 'compose', 'version']);
    Process::assertDidntRun(['docker', 'info']);
});

it('turns a dead daemon into a hint rather than raw docker output', function () {
    Process::fake(fn () => Process::result(
        errorOutput: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
        exitCode: 1,
    ));

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('stack:up');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Docker is installed but not running');
});

it('exports the flight variables so an override can interpolate them', function () {
    expect(app(GlobalStack::class)->environment())->toBe([
        'FLIGHT_DOMAIN' => 'flght.dev',
        'FLIGHT_NETWORK' => 'flight',
        'FLIGHT_HTTP_PORT' => '80',
        'FLIGHT_HTTPS_PORT' => '443',
        'FLIGHT_DOCKER_SOCK' => '/var/run/docker.sock',
    ]);
});

it('writes the compose file before running compose', function () {
    $this->artisan('stack:up')->assertExitCode(0);

    expect(app(GlobalConfig::class)->composeFile())->toBeFile();
});

it('surfaces the docker output when compose fails', function () {
    // A closure, because an array fake would merge with the catch-all fake
    // from beforeEach and never match.
    Process::fake(function ($process) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'compose') && str_ends_with($command, 'up -d --wait')
            ? Process::result(errorOutput: 'network flight not found', exitCode: 1)
            : Process::result('');
    });

    // Capture the output instead of mocking it, since the error panel is
    // written directly to it.
    $exitCode = $this->withoutMockingConsoleOutput()->artisan('stack:up');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('network flight not found');
});
