<?php

use App\Support\Certificate;
use App\Support\GlobalConfig;
use Illuminate\Filesystem\Filesystem;
use Mockery\MockInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Most tests need a temporary configuration directory. They set it the same
| way users do, through the flight.config_dir setting.
|
*/

function flightDirectory(): string
{
    $directory = sys_get_temp_dir().'/flight-test-'.bin2hex(random_bytes(6));

    mkdir($directory, 0755, true);

    config(['flight.config_dir' => $directory]);

    test()->flightDirectory = $directory;

    return $directory;
}

function flightConfig(array $settings = []): string
{
    $directory = test()->flightDirectory ?? flightDirectory();

    file_put_contents(
        $directory.'/config.yaml',
        Yaml::dump($settings)
    );

    return $directory;
}

/**
 * Create a temporary project with the given flight.yml and change into it.
 * Returns its resolved path.
 *
 * @param  array<string, mixed>|string  $flight  settings, or raw YAML
 */
function flightProject(array|string $flight = ['services' => ['php' => null]], string $name = 'myapp'): string
{
    $directory = sys_get_temp_dir().'/flight-project-'.bin2hex(random_bytes(6)).'/'.$name;

    mkdir($directory, 0755, true);

    file_put_contents(
        $directory.'/flight.yml',
        is_string($flight) ? $flight : Yaml::dump($flight, 4)
    );

    test()->originalDirectory ??= getcwd();
    test()->projectDirectory = dirname($directory);

    chdir($directory);

    // On macOS the temporary directory is a symlink, and getcwd() returns
    // the real path.
    return (string) realpath($directory);
}

function removeDirectory(string $directory): void
{
    (new Filesystem)->deleteDirectory($directory);
}

/**
 * The global configuration, reading the temporary directory.
 */
function flightSettings(): GlobalConfig
{
    return app(GlobalConfig::class);
}

/**
 * Fake a valid certificate so commands never run mkcert. Only ensure() and
 * issue() are faked.
 */
function fakeValidCertificate(): MockInterface
{
    $certificate = Mockery::mock(Certificate::class.'[ensure,issue]', [flightSettings()]);
    $certificate->shouldReceive('ensure')->andReturn(false)->byDefault();
    $certificate->shouldReceive('issue')->andReturnNull()->byDefault();

    app()->instance(Certificate::class, $certificate);

    return $certificate;
}

/**
 * Run a callback with VISUAL and EDITOR set, restoring them afterward even
 * when an expectation fails.
 */
function withEditorEnv(?string $visual, ?string $editor, Closure $callback): mixed
{
    putenv($visual === null ? 'VISUAL' : "VISUAL={$visual}");
    putenv($editor === null ? 'EDITOR' : "EDITOR={$editor}");

    try {
        return $callback();
    } finally {
        putenv('VISUAL');
        putenv('EDITOR');
    }
}
