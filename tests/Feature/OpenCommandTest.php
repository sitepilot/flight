<?php

use App\Exceptions\FlightException;
use App\Support\Browser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * Run a callback with BROWSER set, restoring it afterward even when an
 * expectation fails.
 */
function withBrowserEnv(?string $browser, Closure $callback): mixed
{
    putenv($browser === null ? 'BROWSER' : "BROWSER={$browser}");

    try {
        return $callback();
    } finally {
        putenv('BROWSER');
    }
}

beforeEach(function () {
    flightDirectory();
    $this->project = flightProject();

    Process::fake();
});

it('opens the app in the browser', function () {
    $browser = Mockery::mock(Browser::class);
    $browser->shouldReceive('open')->once()->with('https://myapp.flght.dev');
    app()->instance(Browser::class, $browser);

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('open');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Opened https://myapp.flght.dev');
});

it('opens another routed service', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  admin:\n    type: php\n");

    $browser = Mockery::mock(Browser::class);
    $browser->shouldReceive('open')->once()->with('https://myapp-admin.flght.dev');
    app()->instance(Browser::class, $browser);

    $this->artisan('open', ['service' => 'admin'])->assertExitCode(0);
});

it('refuses to open a service without a url', function () {
    file_put_contents($this->project.'/flight.yaml', "app:\n  type: php\nservices:\n  db:\n    type: mariadb\n");

    $browser = Mockery::mock(Browser::class);
    $browser->shouldNotReceive('open');
    app()->instance(Browser::class, $browser);

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('open', ['service' => 'db']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The "db" service has no URL to open.');
});

it('runs the browser from BROWSER with the url', function () {
    withBrowserEnv(PHP_BINARY.' --flag', fn () => (new Browser)->open('https://myapp.flght.dev'));

    Process::assertRan([PHP_BINARY, '--flag', 'https://myapp.flght.dev']);
});

it('explains when BROWSER points at something missing', function () {
    withBrowserEnv('definitely-not-a-browser', fn () => (new Browser)->command());
})->throws(FlightException::class, 'not found in your PATH');

it('explains when the browser fails', function () {
    Process::fake(['*' => Process::result('', 'no display', 1)]);

    withBrowserEnv(PHP_BINARY, fn () => (new Browser)->open('https://myapp.flght.dev'));
})->throws(FlightException::class, 'Could not open https://myapp.flght.dev.');
